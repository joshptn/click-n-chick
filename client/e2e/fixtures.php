<?php

use App\Models\Category;
use App\Models\Food;

$category = Category::create(['name' => 'Rice Meals']);

foreach ([['Chicken Inasal', 160], ['Spicy Fried Chicken', 210], ['Herb Roasted Chicken', 299]] as [$name, $price]) {
    $food = new Food;
    $food->forceFill([
        'food_name' => $name,
        'price' => $price,
        'description' => 'A dish for the browser tests.',
        'thumbnail' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
        'stock_quantity' => 50,
        'is_available' => true,
        'category_id' => $category->id,
    ])->save();
}
