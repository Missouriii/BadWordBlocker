<?php

/**
 * BadWordBlocker | plugin main class
 */

namespace surva\badwordblocker;

use DateInterval;
use DateTime;
use Exception;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class BadWordBlocker extends PluginBase
{
    /**
     * @var \pocketmine\utils\Config default language config
     */
    private Config $defaultMessages;

    /**
     * @var \pocketmine\utils\Config selected language config
     */
    private Config $messages;

    private array $blockedWords;

    private array $playersTimeWritten;

    private array $playersLastWritten;

    private array $playersViolations;

    /**
     * Plugin has been enabled, initial setup
     */
    public function onEnable(): void
    {
        $this->saveDefaultConfig();

        $this->defaultMessages = new Config($this->getFile() . "resources/languages/en.yml");
        $this->messages        = new Config(
            $this->getFile() . "resources/languages/" . $this->getConfig()->get("language", "en") . ".yml"
        );

        $this->blockedWords = $this->getConfig()->get("badwords", ["fuck", "shit", "bitch"]);

        $this->playersTimeWritten = [];
        $this->playersLastWritten = [];
        $this->playersViolations  = [];

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
    }

    /**
     * Check the message of a player on the different aspects (true = alright; false = found something)
     *
     * @param  \pocketmine\player\Player  $player
     * @param  string  $message
     *
     * @return bool
     */
    public function checkMessage(Player $player, string $message): bool
    {
        $playerName = $player->getName();

        if ($this->getConfig()->get("ignorespaces", true) === true) {
            $message = str_replace(" ", "", $message);
        }

        if (!$player->hasPermission("badwordblocker.bypass.swear")) {
            if (($blocked = $this->contains($message, $this->blockedWords)) !== null) {
                if ($this->getConfig()->get("showblocked", false) === true) {
                    $player->sendMessage($this->getMessage("blocked.messagewithblocked", ["blocked" => $blocked]));
                } else {
                    $player->sendMessage($this->getMessage("blocked.message"));
                }

                $this->handleViolation($player);

                return false;
            }
        }

        if (!$player->hasPermission("badwordblocker.bypass.same")) {
            if (isset($this->playersLastWritten[$playerName])) {
                if ($this->playersLastWritten[$playerName] === $message) {
                    $player->sendMessage($this->getMessage("blocked.lastwritten"));
                    $this->handleViolation($player);

                    return false;
                }
            }
        }

        if (!$player->hasPermission("badwordblocker.bypass.spam")) {
            if (isset($this->playersTimeWritten[$playerName])) {
                if ($this->playersTimeWritten[$playerName] > new DateTime()) {
                    $player->sendMessage($this->getMessage("blocked.timewritten"));
                    $this->handleViolation($player);

                    return false;
                }
            }
        }

        if (!$player->hasPermission("badwordblocker.bypass.caps")) {
            $uppercasePercentage = $this->getConfig()->get("uppercasepercentage", 0.75);
            $minimumChars        = $this->getConfig()->get("minimumchars", 3);

            $messageLength = strlen($message);

            if (
                $messageLength > $minimumChars and ($this->countUppercaseChars(
                    $message
                ) / $messageLength) >= $uppercasePercentage
            ) {
                $player->sendMessage($this->getMessage("blocked.caps"));
                $this->handleViolation($player);

                return false;
            }
        }

        try {
            $this->playersTimeWritten[$playerName] = new DateTime();
            $this->playersTimeWritten[$playerName] = $this->playersTimeWritten[$playerName]->add(
                new DateInterval("PT" . $this->getConfig()->get("waitingtime", 2) . "S")
            );
            $this->playersLastWritten[$playerName] = $message;
        } catch (Exception $exception) {
            return false;
        }

        return true;
    }

    /**
     * Handle the occurrence of a chat block event, e.g. kick or ban the player if configured
     *
     * @param  \pocketmine\player\Player  $player
     */
    private function handleViolation(Player $player): void
    {
        $playerName = $player->getName();

        if (!isset($this->playersViolations[$playerName])) {
            $this->playersViolations[$playerName] = 0;
        }

        $this->playersViolations[$playerName]++;

        $violKick       = $this->getConfig()->getNested("violations.kick", 0);
        $violBan        = $this->getConfig()->getNested("violations.ban", 0);
        $resetAfterKick = $this->getConfig()->getNested("violations.resetafterkick", true);

        if ($this->playersViolations[$playerName] === $violKick) {
            $player->kick($this->getMessage("kick"));

            if ($resetAfterKick) {
                $this->playersViolations[$playerName] = 0;
            }
        } elseif ($this->playersViolations[$playerName] === $violBan) {
            $this->getServer()->getNameBans()->addBan($playerName, $this->getMessage("ban"));
            $player->kick($this->getMessage("ban"));

            $this->playersViolations[$playerName] = 0;
        }
    }

    /**
     * Check if a string contains a specific string from an array and return it
     *
     * @param  string  $string
     * @param  array  $contains
     *
     * @return string|null
     */
    private function contains(string $string, array $contains): ?string
    {
        foreach ($contains as $contain) {
            if (str_contains(strtolower($string), $contain)) {
                return $contain;
            }
        }

        return null;
    }

    /**
     * Counts uppercase chars in a string
     *
     * @param  string  $string
     *
     * @return int
     */
    private function countUppercaseChars(string $string): int
    {
        preg_match_all("/[A-Z]/", $string, $matches);

        return count($matches[0]);
    }

    /**
     * Get a translated message
     *
     * @param  string  $key
     * @param  array  $replaces
     *
     * @return string
     */
    public function getMessage(string $key, array $replaces = []): string
    {
        $rawMessage = $this->messages->getNested($key);

        if ($rawMessage === null || $rawMessage === "") {
            $rawMessage = $this->defaultMessages->getNested($key);
        }

        if ($rawMessage === null) {
            return $key;
        }

        foreach ($replaces as $replace => $value) {
            $rawMessage = str_replace("{" . $replace . "}", $value, $rawMessage);
        }

        return $rawMessage;
    }
}
