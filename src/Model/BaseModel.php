<?php
/**
 * Order management package for CartRabbit
 *
 * (c) Ramesh Elamathi <ramesh@flycart.org>
 * For the full copyright and license information, please view the LICENSE file
 * that was distribute as a part of the source code
 *
 */
namespace Flycartinc\Order\Model;

use Corcel\Model as CorcelModel;
use Corcel\Post;

class BaseModel extends CorcelModel
{

    public function getMetaOf($post_type, $meta_key)
    {
        $post = Post::where('post_type', $post_type)->get()->first();
        if (empty($post)) return array();
        $meta = $post->meta()->get()->where('meta_key', $meta_key)->pluck('meta_value', 'meta_key')->first();
        return (!empty($meta)) ? $meta : null;
    }

}
