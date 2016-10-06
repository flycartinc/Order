<?php

namespace Flycartinc\Order\Model;

/**
 * Class order_items
 * @package CartRabbit\Models
 */
class OrderItemMeta extends BaseModel
{
    /**
     * To Set Table Name
     * @var string
     */
    protected $table = 'cartrabbit_order_itemmeta';

    /**
     * To Set Fillable fields in the table
     * @var array
     */
    protected $fillable = [
        'order_item_id',
        'meta_key',
        'meta_value'
    ];

    /**
     * To Set Primart Key
     * @var string
     */
    protected $primaryKey = 'meta_id';

    protected $itemMeta = array();

    /**
     * Order relationship
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function orderitem($ref = false)
    {
        if ($ref) {
            $this->primaryKey = 'meta_value';

            return $this->hasOne('Flycartinc\Order\Model\OrderItem', 'id');
        }

        return $this->belongsTo('Flycartinc\Order\Model\OrderItem', 'order_item_id', 'meta_id');
    }


}
