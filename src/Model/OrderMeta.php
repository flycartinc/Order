<?php
namespace FlycartInc\Order\Models;

use Exception;

class PostMeta extends Model {


	protected $table = 'storepress_order_meta';
	protected $primaryKey = 'meta_id';
	public $timestamps = false;
	protected $fillable = [ 'meta_key', 'meta_value', 'order_id' ];



	/**
	 * Order relationship
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function order($ref = false)
	{
		if ($ref) {
			$this->primaryKey = 'meta_value';

			return $this->hasOne('Flycartinc\Order\Models\Order', 'order_id');
		}

		return $this->belongsTo('Flycartinc\Order\Models\Order');
	}


}