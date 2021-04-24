<?php

namespace ethaniccc\Esoteric\check\combat\range;

use ethaniccc\Esoteric\check\Check;
use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\utils\AABB;
use ethaniccc\Esoteric\utils\Ray;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

class RangeA extends Check {

	private $waiting = false;

	public function __construct() {
		parent::__construct("Range", "A", "Checking if the player's attack range exceeds a certain limit", false);
	}

	public function inbound(DataPacket $packet, PlayerData $data): void {
		if ($packet instanceof InventoryTransactionPacket && $packet->trData->getTypeId() === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK && in_array($data->gamemode, [GameMode::SURVIVAL, GameMode::ADVENTURE])) {
			$this->waiting = true;
		} elseif ($packet instanceof PlayerAuthInputPacket && $this->waiting) {
			if ($data->currentTick - $data->attackTick <= 2) {
				$locationData = $data->entityLocationMap->get($data->target);
				if ($locationData !== null) {
					if ($locationData->isSynced <= 10) {
						return;
					}
					$AABB = AABB::fromPosition($locationData->lastLocation)->expand(0.1, 0.1, 0.1);
					if ($data->isMobile) {
						$rawDistance = $AABB->distanceFromVector($data->attackPos);
						if ($rawDistance > $this->option("max_raw", 3.05)) {
							if (++$this->buffer >= 3) {
								$this->flag($data, ["dist" => round($rawDistance, 3), "type" => "raw"]);
								$this->buffer = min($this->buffer, 4.5);
							}
						} else {
							$this->buffer = max($this->buffer - 0.05, 0);
						}
					} else {
						$ray = Ray::from($data);
						$intersection = $AABB->calculateIntercept($ray->origin, $ray->traverse(7));
						if ($intersection !== null && !$AABB->isVectorInside($data->attackPos)) {
							$raycastDist = $intersection->getHitVector()->distance($data->attackPos);
							if ($raycastDist > $this->option("max_dist", 3.01)) {
								if (++$this->buffer >= 3) {
									$this->flag($data, ["dist" => round($raycastDist, 3), "type" => "raycast"]);
									$this->buffer = min($this->buffer, 4.5);
								}
							} else {
								$this->buffer = max($this->buffer - 0.05, 0);
							}
						}
					}
				}
			}
			$this->waiting = false;
		}
	}

}