<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    use HasFactory;

    protected $fillable = 
    [
        'user_chat_id',
        'company_id',
        'status',
        'image',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
