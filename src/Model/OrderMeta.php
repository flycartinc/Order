<?php
namespace FlycartInc\Order\Model;

class OrderMeta extends BaseModel
{

    protected $table = 'cartrabbit_ordermeta';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $fillable = ['meta_key', 'meta_value', 'order_id'];


    /**
     * Order relationship
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order($ref = false)
    {
        if ($ref) {
            $this->primaryKey = 'meta_value';

            return $this->hasOne('Flycartinc\Order\Model\Order', 'id');
        }

        return $this->belongsTo('Flycartinc\Order\Model\Order', 'order_id', 'id');
    }

}
