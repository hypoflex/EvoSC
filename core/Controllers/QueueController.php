<?php

namespace esc\Controllers;


use esc\Classes\Hook;
use esc\Classes\ManiaLinkEvent;
use esc\Interfaces\ControllerInterface;
use esc\Models\AccessRight;
use esc\Models\Map;
use esc\Models\MapQueue;
use esc\Models\Player;
use Illuminate\Support\Collection;

/**
 * Class QueueController
 * 
 * The QueueController handles adding/removing maps to/from the queue.
 *
 * @package esc\Controllers
 */
class QueueController implements ControllerInterface
{
    /**
     * Initialize QueueController
     */
    public static function init()
    {
        Hook::add('PlayerDisconnect', [self::class, 'playerDisconnect']);

        ManiaLinkEvent::add('map.queue', [self::class, 'manialinkQueueMap']);
        ManiaLinkEvent::add('map.drop', [self::class, 'dropMap']);

        AccessRight::createIfNonExistent('map_queue_recent', 'Drop maps from queue.');
        AccessRight::createIfNonExistent('queue_drop', 'Drop maps from queue.');
        AccessRight::createIfNonExistent('queue_multiple', 'Queue more than one map.');
        AccessRight::createIfNonExistent('queue_keep', 'Keep maps in queue if player leaves.');
    }

    /**
     * Queue a map.
     * 
     * @param Player $player
     * @param Map    $map
     * @param bool               $replay
     */
    public static function queueMap(Player $player, Map $map, bool $replay = false)
    {
        if ($map->cooldown < config('server.map-cooldown') && !$player->hasAccess('map_queue_recent')) {
            warningMessage('Can not queue recently played track. Please wait ' . secondary(config('server.map-cooldown') - $map->cooldown) . ' maps.')->send($player);

            return;
        }

        if (MapQueue::whereMapUid($map->uid)->count() > 0) {
            warningMessage('The map ', secondary($map), ' is already in queue.')->send($player);

            return;
        }

        if (MapQueue::whereRequestingPlayer($player->Login)->count() > 0) {
            if (!$player->hasAccess('queue_multiple')) {
                warningMessage('You are only allowed to queue one map at a time.')->send($player);

                return;
            }
        }

        MapQueue::create([
            'requesting_player' => $player->Login,
            'map_uid'           => $map->uid,
        ]);

        if ($replay) {
            infoMessage($player, ' queued map ', secondary($map), ' for replay.')->sendAll();
        } else {
            infoMessage($player, ' queued map ', secondary($map), '.')->sendAll();
        }

        Hook::fire('MapQueueUpdated', self::getMapQueue());
    }

    /**
     * Drop a map from queue.
     * 
     * @param Player $player
     * @param                    $mapUid
     */
    public static function dropMap(Player $player, $mapUid)
    {
        $queueItem = MapQueue::whereMapUid($mapUid)->first();

        if ($queueItem) {
            if ($queueItem->requesting_player != $player->Login && !$player->hasAccess('queue_drop')) {
                warningMessage('You can not drop others players maps.')->send($player);

                return;
            }

            infoMessage($player, ' drops ', secondary($queueItem->map), ' from queue.')->sendAll();
            MapQueue::whereMapUid($mapUid)->delete();
            Hook::fire('MapQueueUpdated', self::getMapQueue());
        }
    }

    /**
     * ManiaLinkEvent: queue map
     * 
     * @param Player $player
     * @param                    $mapUid
     */
    public static function manialinkQueueMap(Player $player, $mapUid)
    {
        $map = Map::whereUid($mapUid)->first();

        if ($map) {
            QueueController::queueMap($player, $map);
        }
    }

    /**
     * Get maps in queue sorted by adding time.
     * 
     * @return \Illuminate\Support\Collection
     */
    public static function getMapQueue(): Collection
    {
        return MapQueue::orderBy('created_at')->get();
    }

    /**
     * Called on PlayerDisconnect
     * 
     * @param Player $player
     */
    public static function playerDisconnect(Player $player)
    {
        $queryBuilder = MapQueue::whereRequestingPlayer($player->Login);

        if (!$player->hasAccess('queue_keep') && $queryBuilder->count() > 0) {
            $queryBuilder->get()->filter(function (MapQueue $item) use ($player) {
                infoMessage('Dropped ', secondary($item->map), ' from queue, because ', secondary($player), ' left.')->sendAll();
                MapQueue::whereMapUid($item->map_uid)->delete();
            });

            Hook::fire('MapQueueUpdated', self::getMapQueue());
        }
    }
}