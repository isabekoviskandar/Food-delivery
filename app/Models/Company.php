<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = 
    [
        'name',
        'email',
        'status',
        'owned_id',
    ];

    public function worker()
    {
        return $this->hasMany(Worker::class , 'company_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
