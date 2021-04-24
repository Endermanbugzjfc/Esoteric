<?php

namespace ethaniccc\Esoteric\listener;

use ethaniccc\Esoteric\Esoteric;
use ethaniccc\Esoteric\utils\PacketUtils;
use LogicException;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\MoveActorDeltaPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Binary;
use pocketmine\utils\TextFormat;
use RuntimeException;

class PMMPListener implements Listener {

	/** @var TimingsHandler */
	public $checkTimings;
	public $sendTimings;
	public $decodingTimings;

	public function __construct() {
		$this->checkTimings = new TimingsHandler("Esoteric Checks");
		$this->sendTimings = new TimingsHandler("Esoteric Listener Outbound");
		$this->decodingTimings = new TimingsHandler("Esoteric Batch Decoding");
	}

	/**
	 * @param PlayerPreLoginEvent $event
	 * @priority LOWEST
	 */
	public function log(PlayerPreLoginEvent $event): void {
		foreach (Server::getInstance()->getNameBans()->getEntries() as $entry) {
			if ($entry->getSource() === "Esoteric AC") {
				$event->setCancelled();
				$event->setKickMessage($entry->getReason());
				break;
			}
		}
	}

	/**
	 * @param PlayerQuitEvent $event
	 * @priority LOWEST
	 */
	public function quit(PlayerQuitEvent $event): void {
		$data = Esoteric::getInstance()->dataManager->get($event->getPlayer());
		$message = null;
		foreach ($data->checks as $check) {
			$checkData = $check->getData();
			if ($checkData["violations"] >= 1) {
				if ($message === null) {
					$message = "";
				}
				$message .= TextFormat::YELLOW . $checkData["full_name"] . TextFormat::WHITE . " - " . $checkData["description"] . TextFormat::GRAY . " (" . TextFormat::RED . "x" . var_export(round($checkData["violations"], 3), true) . TextFormat::GRAY . ")" . PHP_EOL;
			}
		}
		Esoteric::getInstance()->logCache[strtolower($event->getPlayer()->getName())] = $message === null ? TextFormat::GREEN . "This player has no logs" : $message;
		Esoteric::getInstance()->dataManager->remove($event->getPlayer());
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled false
	 */
	public function inbound(DataPacketReceiveEvent $event): void {
		$packet = $event->getPacket();
		$player = $event->getPlayer();
		$playerData = Esoteric::getInstance()->dataManager->get($player) ?? Esoteric::getInstance()->dataManager->add($player);
		if ($playerData->isDataClosed) {
			return;
		}
		$playerData->inboundProcessor->execute($packet, $playerData);
		$this->checkTimings->startTiming();
		foreach ($playerData->checks as $check) {
			if ($check->enabled()) {
				$check->getTimings()->startTiming();
				$check->inbound($packet, $playerData);
				$check->getTimings()->stopTiming();
			}
		}
		$this->checkTimings->stopTiming();
		if ($packet instanceof PlayerAuthInputPacket) {
			$event->setCancelled();
		}
	}

	/**
	 * @param DataPacketSendEvent $event
	 * @priority LOWEST
	 */
	public function outbound(DataPacketSendEvent $event): void {
		$packet = $event->getPacket();
		$player = $event->getPlayer();
		$playerData = Esoteric::getInstance()->dataManager->get($player);
		if ($playerData === null) {
			return;
		}
		if ($playerData->isDataClosed) {
			return;
		}
		if ($packet instanceof BatchPacket) {
			$this->sendTimings->startTiming();
			$gen = PacketUtils::getAllInBatch($packet);
			foreach ($gen as $buff) {
				$pk = PacketPool::getPacket($buff);
				$this->decodingTimings->startTiming();
				try {
					try {
						$pk->decode();
					} catch (RuntimeException $e) {
						continue;
					}
				} catch (LogicException $e) {
					continue;
				}
				$this->decodingTimings->stopTiming();
				if (($pk instanceof MovePlayerPacket || $pk instanceof MoveActorDeltaPacket) && $pk->entityRuntimeId !== $playerData->player->getId()) {
					$packet->buffer = str_replace(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, "", $packet->buffer);
					$packet->payload = str_replace(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, "", $packet->payload);
					if (count($gen) === 1) {
						// if this BS is sent to the client, the client will crash
						$event->setCancelled();
					}
					$playerData->entityLocationMap->add($pk);
				}
				$playerData->outboundProcessor->execute($pk, $playerData);
				foreach ($playerData->checks as $check)
					if ($check->handleOut())
						$check->outbound($pk, $playerData);
			}
			$this->sendTimings->stopTiming();
		} elseif ($packet instanceof StartGamePacket) {
			$movementSettings = new PlayerMovementSettings(PlayerMovementType::SERVER_AUTHORITATIVE_V2_REWIND, 0, false);
			$packet->playerMovementSettings = $movementSettings;
		}
	}

	public function onLevelChange(EntityLevelChangeEvent $event): void {
		$entity = $event->getEntity();
		if ($entity instanceof Player) {
			$data = Esoteric::getInstance()->dataManager->get($entity);
			if ($data !== null) {
				$data->inLoadedChunk = false;
			}
		}
	}

}