<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zyntrodata\Payments\Model\Polling;

class ProtectExistingOrders implements DataPatchInterface
{
    private ResourceConnection $resource;
    private OrderRepositoryInterface $orders;
    private Polling $polling;
    public function __construct(ResourceConnection $resource, OrderRepositoryInterface $orders, Polling $polling)
    { $this->resource = $resource; $this->orders = $orders; $this->polling = $polling; }
    public function apply()
    {
        $db = $this->resource->getConnection('sales');
        $last = 0;
        do {
            $ids = $db->fetchCol($db->select()->from($this->resource->getTableName('sales_order_payment'), ['parent_id'])
                ->where('method = ?', 'zyntrodata')->where('parent_id > ?', $last)->order('parent_id ASC')->limit(200));
            foreach ($ids as $id) {
                $order = $this->orders->get((int)$id);
                Polling::protect($order);
                $this->polling->schedule($order);
                $this->orders->save($order);
                $last = (int)$id;
            }
        } while (count($ids) === 200);
        return $this;
    }
    public static function getDependencies() { return []; }
    public function getAliases() { return []; }
}
