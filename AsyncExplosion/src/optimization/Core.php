<?php

namespace optimization;

use pocketmine\block\Block;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\UnknownBlock;
use pocketmine\scheduler\Task;
use pocketmine\Server;

final class Core extends PluginBase implements Listener
{

	private $queueTask;

	public function onEnable(): void
	{
		$this->queueTask = new class extends Task
		{
			private array $queue = [];

			public function addInQueue(array $vectors, int $worldId): void
			{
				if (!isset($this->queue[$worldId])) {
					$this->initQueue($worldId);
				}

				$currentVectors = &$this->queue[$worldId]["vectors"];
				foreach ($vectors as $vector) {
					$key = $this->vectorToKey($vector);
					if (!isset($currentVectors[$key])) {
						$currentVectors[$key] = $vector;
					}
				}
			}

			private function initQueue(int $worldId): void
			{
				$this->queue[$worldId] = ["vectors" => []];
			}

			public function onRun(): void
			{
				foreach ($this->queue as $worldId => $data) {
					if (!empty($data["vectors"])) {
						Server::getInstance()->getAsyncPool()->submitTask(new AsyncProcess(array_values($data["vectors"]), $worldId));
						$this->initQueue($worldId);
					}
				}
			}

			private function vectorToKey(Vector3 $vector): string
			{
				return $vector->getFloorX() . ":" . $vector->getFloorY() . ":" . $vector->getFloorZ();
			}
		};

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask($this->queueTask, 20);
	}

	public function onEntityExplode(EntityExplodeEvent $event): void
	{
		$blockList = array_filter($event->getBlockList(), function ($block) {
			return !($block instanceof UnknownBlock);
		});
		$event->setBlockList([]);

		$vectors = array_map(static function (Block $block): Vector3 {
			return $block->getPosition()->asVector3()->floor();
		}, $blockList);

		$this->queueTask->addInQueue($vectors, $event->getPosition()->getWorld()->getId());
		$event->setBlockList(array_filter($blockList, static function (Block $block): bool {
			return $block->getTypeId() === BlockTypeIds::TNT;
		}));
	}
}
