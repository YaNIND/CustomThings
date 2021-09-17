<?php

namespace romainsav\customthings;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIds;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\tile\ItemFrame;
use romainsav\customthings\items\classes\ToolClass;
use romainsav\customthings\items\ItemManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\plugin\PluginBase;

class Loader extends PluginBase implements Listener {

    /** @var Loader */
    private static Loader $instance;

    /** @var TaskHandler[][] */
    private array $handlers = [];

    public function onLoad(): void {
        self::$instance = $this;
    }

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("items.json");
        new ItemManager();
    }

    public function onPacketSend(DataPacketSendEvent $event): void {
        $packet = $event->getPacket();
        if ($packet instanceof StartGamePacket) {
            $packet->itemTable = ItemManager::getInstance()->getEntries();
        }

        if ($packet instanceof PlayerActionPacket) {
            /**
             * Thanks to @alvin0319 for this function
             */
            $handled = false;

            try {
                $pos = new Vector3($packet->x, $packet->y, $packet->z);
                $player = $event->getPlayer();
                if ($packet->action === PlayerActionPacket::ACTION_START_BREAK) {
                    $item = $player->getInventory()->getItemInHand();
                    if (!($item instanceof ToolClass)) return;

                    if ($pos->distanceSquared($player) > 10000) return;

                    $target = $player->getLevelNonNull()->getBlock($pos);

                    $ev = new PlayerInteractEvent($player, $player->getInventory()->getItemInHand(), $target, null, $packet->face, PlayerInteractEvent::LEFT_CLICK_BLOCK);
                    if ($player->isSpectator() || $player->getLevelNonNull()->checkSpawnProtection($player, $target)) {
                        $ev->setCancelled();
                    }

                    $ev->call();
                    if ($ev->isCancelled()) {
                        $player->getInventory()->sendHeldItem($player);
                        return;
                    }

                    $tile = $player->getLevelNonNull()->getTile($pos);
                    if ($tile instanceof ItemFrame && $tile->hasItem()) {
                        if (lcg_value() <= $tile->getItemDropChance()) {
                            $player->getLevelNonNull()->dropItem($tile->getBlock(), $tile->getItem());
                        }
                        $tile->setItem();
                        $tile->setItemRotation(0);
                        return;
                    }
                    $block = $target->getSide($packet->face);
                    if ($block->getId() === BlockIds::FIRE) {
                        $player->getLevelNonNull()->setBlock($block, BlockFactory::get(BlockIds::AIR));
                        return;
                    }

                    if (!$player->isCreative()) {
                        $handled = true;
                        //TODO: improve this to take stuff like swimming, ladders, enchanted tools into account, fix wrong tool break time calculations for bad tools (pmmp/PocketMine-MP#211)
                        $breakTime = ceil($target->getBreakTime($player->getInventory()->getItemInHand()) * 20);
                        if ($breakTime > 0) {
                            if ($breakTime > 10) {
                                $breakTime -= 10;
                            }
                            $this->scheduleTask(Position::fromObject($pos, $player->getLevelNonNull()), $player->getInventory()->getItemInHand(), $player, $breakTime);
                            $player->getLevelNonNull()->broadcastLevelEvent($pos, LevelEventPacket::EVENT_BLOCK_START_BREAK, (int)(65535 / $breakTime));
                        }
                    }
                } elseif ($packet->action === PlayerActionPacket::ACTION_ABORT_BREAK) {
                    $player->getLevelNonNull()->broadcastLevelEvent($pos, LevelEventPacket::EVENT_BLOCK_STOP_BREAK);
                    $handled = true;
                    $this->stopTask($player, Position::fromObject($pos, $player->getLevelNonNull()));
                }
            } finally {
                if ($handled) {
                    $event->setCancelled();
                }
            }
        }
    }

    private function scheduleTask(Position $pos, Item $item, Player $player, float $breakTime): void {
        /**
         * Thanks to @alvin0319 for this function
         */
        $handler = $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (int $_) use ($pos, $item, $player): void {
            $pos->getLevelNonNull()->useBreakOn($pos, $item, $player);
            unset($this->handlers[$player->getName()][$this->blockHash($pos)]);
        }), (int)floor($breakTime));
        if (!isset($this->handlers[$player->getName()])) {
            $this->handlers[$player->getName()] = [];
        }
        $this->handlers[$player->getName()][$this->blockHash($pos)] = $handler;
    }

    private function stopTask(Player $player, Position $pos): void {
        /**
         * Thanks to @alvin0319 for this function
         */
        if (!isset($this->handlers[$player->getName()][$this->blockHash($pos)])) {
            return;
        }
        $handler = $this->handlers[$player->getName()][$this->blockHash($pos)];
        $handler->cancel();
        unset($this->handlers[$player->getName()][$this->blockHash($pos)]);
    }

    private function blockHash(Position $pos): string {
        /**
         * Thanks to @alvin0319 for this function
         */
        return implode(":", [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $pos->getLevelNonNull()->getFolderName()]);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $player->sendDataPacket(ItemManager::getInstance()->getPacket());
        $player->getInventory()->sendCreativeContents();
    }

    /**
     * @return Loader
     */
    public static function getInstance(): Loader {
        return self::$instance;
    }
}
