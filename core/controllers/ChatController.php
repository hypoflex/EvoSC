<?php

namespace esc\controllers;


use esc\classes\ChatCommand;
use esc\classes\File;
use esc\classes\Log;
use esc\classes\Template;
use esc\models\Player;
use Illuminate\Database\Eloquent\Collection;
use Maniaplanet\DedicatedServer\Xmlrpc\FaultException;

class ChatController
{
    /* maniaplanet chat styling
$i: italic
$s: shadowed
$w: wide
$n: narrow
$m: normal
$g: default color
$o: bold
$z: reset all
$t: Changes the text to capitals
$$: Writes a dollarsign
     */

    private static $pattern;
    private static $chatCommands;

    public static function initialize()
    {
        self::$chatCommands = new Collection();

        ServerController::call('ChatEnableManualRouting', [true, false]);

        HookController::add('PlayerChat', 'esc\controllers\ChatController::playerChat');

        Template::add('help', File::get('core/Templates/help.latte.xml'));

        self::addCommand('help', '\esc\controllers\ChatController::showHelp', 'Show this help');
    }

    private static function getChatCommands(): Collection
    {
        return self::$chatCommands;
    }

    public static function showHelp(Player $player)
    {
        $commands = self::getChatCommands()->filter(function (ChatCommand $command) use ($player) {
            return $command->hasAccess($player);
        })->sortBy('trigger');

        Template::show($player, 'help', ['commands' => $commands]);
    }

    public static function playerChat(Player $player, $text, $isRegisteredCmd)
    {
        if (preg_match(self::$pattern, $text, $matches)) {
            if (self::executeChatCommand($player, $text)) {
                return;
            }
        }

        Log::chat($player->NickName, $text);
        $nick = $player->NickName;

        if (preg_match('/\$l\[(http.+)\](http.+)[ ]?/', $text, $matches)) {
            if (strlen($matches[2]) > 50) {
                $restOfString = explode(' ', $matches[2]);
                $urlName = array_shift($restOfString);
                $short = substr($urlName, 0, 28) . '..' . substr($urlName, -10);
                $short = preg_replace('/^https?:\/\//', '', $short);
                if (preg_match('/^https/', $matches[1])) {
                    $short = "🔒" . $short;
                }
                $newUrl = $short . '$z $s' . implode(' ', $restOfString);
                $text = str_replace("\$l[$matches[1]]$matches[2]", "\$l[$matches[1]]$newUrl", $text);
            }
        }

        if (preg_match('/([$]+)$/', $text, $matches)) {
            $text .= $matches[0];
        }

        $text = preg_replace('/\$[nb]/', '', $text);

        if ($player->isSpectator()) {
            $nick = '$eee📷$z ' . $nick;
        }

        $chatText = sprintf('$z$s%s: $fe2$z$s%s', $nick, $text);

        echo "$chatText\n";

        ServerController::call('ChatSendServerMessage', [$chatText]);
    }

    private static function executeChatCommand(Player $player, string $text): bool
    {
        $isValidCommand = false;

        if (preg_match_all('/\"(.+?)\"/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $new = str_replace(' ', ';:;', $match);
                $text = str_replace("\"$match\"", $new, $text);
            }
        }

        $arguments = explode(' ', $text);

        foreach ($arguments as $key => $argument) {
            $arguments[$key] = str_replace(';:;', ' ', $argument);
        }

        $command = self::$chatCommands
            ->filter(function (ChatCommand $cmd) use ($arguments) {
                return "$cmd->trigger$cmd->command" == $arguments[0];
            })
            ->first();

        array_unshift($arguments, $player);

        if ($command) {
            call_user_func_array($command->callback, $arguments);
            $isValidCommand = true;
        }

        return $isValidCommand;
    }

    public static function addCommand(string $command, string $callback, string $description = '-', string $trigger = '/', array $access = null)
    {
        $chatCommand = new ChatCommand($trigger, $command, $callback, $description, $access);
        self::$chatCommands->add($chatCommand);

        $triggers = [];
        $chatCommandPattern = '/^(';
        foreach (self::$chatCommands as $cmd) {
            array_push($triggers, '\\' . implode('\\', str_split($cmd->trigger)) . $cmd->command);
        }
        $chatCommandPattern .= implode('|', $triggers) . ')/';
        self::$pattern = $chatCommandPattern;

        Log::info("Chat command added: $trigger$command -> $callback");
    }

    public static function messageAll(string $message)
    {
        try {
            ServerController::getRpc()->chatSendServerMessage('$18f' . $message);
        } catch (FaultException $e) {
            Log::error($e);
        }
    }

    public static function message(Player $player, string $message)
    {
        try {
            ServerController::getRpc()->chatSendServerMessage($message, $player->Login);
        } catch (FaultException $e) {
            Log::error($e);
        }
    }
}