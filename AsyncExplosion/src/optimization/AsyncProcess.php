<?php

namespace optimization;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\UnknownBlock;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\World;
use function base64_encode;
use function base64_decode;
use function json_encode;
use function json_decode;

final class AsyncProcess extends AsyncTask
{
    private string $chunks;
    private string $vectors;
    private int $worldId;

    public function __construct(array $vectors, int $worldId)
    {
        $this->chunks = self::serialize($vectors, $worldId);
        $this->vectors = json_encode(array_map(fn (Vector3 $v) => ['x' => $v->getX(), 'y' => $v->getY(), 'z' => $v->getZ()], $vectors), JSON_THROW_ON_ERROR);
        $this->worldId = $worldId;
    }

    public function onRun(): void
    {
        $chunks = json_decode($this->chunks, true, 512, JSON_THROW_ON_ERROR);
        $vectors = json_decode($this->vectors, true, 512, JSON_THROW_ON_ERROR);

        foreach ($chunks as $hash => $chunkData) {
            $chunks[$hash] = FastChunkSerializer::deserializeTerrain(base64_decode($chunkData));
        }


        foreach ($vectors as $vectorData) {
            $vector = new Vector3($vectorData['x'], $vectorData['y'], $vectorData['z']);
            $index = World::chunkHash((int)$vector->getX() >> 4, (int)$vector->getZ() >> 4);
            if (isset($chunks[$index])) {
                $x = (int)$vector->getX() & Chunk::COORD_MASK;
                $z = (int)$vector->getZ() & Chunk::COORD_MASK;
                $blockStateId = $chunks[$index]->getBlockStateId($x, $vector->getY(), $z);
                $block = RuntimeBlockStateRegistry::getInstance()->fromStateId($blockStateId);
                if ($block instanceof UnknownBlock) {
                    continue;
                }

                $chunks[$index]->setBlockStateId($x, $vector->getY(), $z, VanillaBlocks::AIR()->getStateId());
            }
        }
        $this->setResult(['chunks' => array_map(fn ($chunk) => base64_encode(FastChunkSerializer::serializeTerrain($chunk)), $chunks)]);
    }

    public function onCompletion(): void
    {
        $world = Server::getInstance()->getWorldManager()->getWorld($this->worldId);

        $chunks = $this->getResult()['chunks'];

        foreach ($chunks as $hash => $chunkData) {
            $chunk = FastChunkSerializer::deserializeTerrain(base64_decode($chunkData));
            World::getXZ($hash, $chunkX, $chunkZ);
            $world->setChunk($chunkX, $chunkZ, $chunk);
        }
    }

    private static function serialize(array $vectors, int $worldId): string
    {
        $chunks = [];
        $world = Server::getInstance()->getWorldManager()->getWorld($worldId);

        foreach ($vectors as $vector) {
            $x = $vector->getX() >> 4;
            $z = $vector->getZ() >> 4;
            $chunk = $world->getChunk($x, $z);

            if ($chunk === null) {
                continue;
            }

            $chunks[World::chunkHash($x, $z)] = base64_encode(FastChunkSerializer::serializeTerrain($chunk));
        }

        return json_encode($chunks, JSON_THROW_ON_ERROR);
    }
}
