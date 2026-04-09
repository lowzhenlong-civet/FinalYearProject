<?php
class MenuItem
{
    public $id;
    public $merchant_id;
    public $name;
    public $category;
    public $description;
    public $price;
    public $prepTime;
    public $available;
    public $image;
    public $created_at;
    public $approval_status;

    public function __construct($idOrRow, $name = null, $category = null, $description = null, $price = null, $prepTime = null, $available = true, $image = null)
    {
        if (is_array($idOrRow)) {
            $row = $idOrRow;
            $this->id = isset($row['id']) ? (int) $row['id'] : null;
            $this->merchant_id = isset($row['merchant_id']) ? (int) $row['merchant_id'] : null;
            $this->name = isset($row['name']) ? (string) $row['name'] : '';
            $this->category = isset($row['category']) ? (string) $row['category'] : '';
            $this->description = isset($row['description']) ? (string) $row['description'] : '';
            $this->price = isset($row['price']) ? (float) $row['price'] : 0.0;
            $this->prepTime = isset($row['prep_time']) ? (int) $row['prep_time'] : (isset($row['prepTime']) ? (int) $row['prepTime'] : 0);
            $this->available = isset($row['available']) ? (int) $row['available'] == 1 : true;
            $this->image = isset($row['image']) ? (string) $row['image'] : null;
            $this->created_at = $row['created_at'] ?? null;
            $this->approval_status = isset($row['approval_status']) ? trim((string) $row['approval_status']) : null;
            return;
        }

        $this->id = $idOrRow;
        $this->merchant_id = null;
        $this->name = $name;
        $this->category = $category;
        $this->description = $description;
        $this->price = $price;
        $this->prepTime = $prepTime;
        $this->available = $available;
        $this->image = $image;
        $this->created_at = null;
        $this->approval_status = null;
    }
}
?>