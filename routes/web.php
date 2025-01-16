<?php

use App\Http\Controllers\FoodController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/' , [FoodController::class , 'index']);