<?php
require_once __DIR__ . '/db_config.php';

// Category name → default available_times mapping
$catTimeMap = [
    'Morning Breakfast' => 'Morning Breakfast',
    'Lunch'             => 'Lunch',
    'Dinner'            => 'Dinner',
    'Snacks'            => 'Snacks',
    'Coffee Time'       => 'Coffee Time',
    'Ice Creams'        => 'Ice Creams',
    'Cool Drinks'       => 'Cool Drinks',
    // These are multi-time categories — show across multiple meal times
    'Starters'          => 'Lunch,Dinner',
    'Main Course'       => 'Lunch,Dinner',
    'Beverages'         => 'Coffee Time,Cool Drinks',
    'Desserts'          => 'Lunch,Dinner,Snacks',
    'Fast Food'         => 'Lunch,Snacks,Dinner',
];

$updated = 0;

// Get all categories
$cats = [];
$cr = $conn->query("SELECT id, name FROM categories");
while($c = $cr->fetch_assoc()) $cats[$c['id']] = $c['name'];

// Update items that still have 'all' — set based on their category
$items = $conn->query("SELECT id, category_id, name, available_times FROM menu_items");
while($item = $items->fetch_assoc()){
    $catId   = (int)$item['category_id'];
    $catName = $cats[$catId] ?? '';
    $current = $item['available_times'];

    if($current === 'all' && isset($catTimeMap[$catName])){
        $newTime = $conn->real_escape_string($catTimeMap[$catName]);
        $conn->query("UPDATE menu_items SET available_times='$newTime' WHERE id={$item['id']}");
        $updated++;
        echo "Updated: {$item['name']} (cat: $catName) -> $newTime\n";
    }
}

echo "\nTotal updated: $updated\n";

// Verify
$r = $conn->query("SELECT id, name, available_times FROM menu_items ORDER BY id");
echo "\n--- Final state ---\n";
while($row = $r->fetch_assoc()){
    echo "ID:{$row['id']} [{$row['available_times']}] {$row['name']}\n";
}
?>
