<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetailerPricing extends Model
{
    // Table name "retailer_pricings" is Laravel's default for this model, so nothing to override
    protected $fillable = ['retailer_id', 'service_id', 'custom_price', 'effective_from', 'effective_to'];
}
