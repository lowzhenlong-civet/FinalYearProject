<?php
require_once __DIR__ . '/../config/database.php';

class MenuItem {
    public $id;
    public $name;
    public $category;
    public $description;
    public $price;
    public $prepTime;
    public $available;
    public $image;
    public $merchant_id;
    public $merchant_email;
    public $approval_status;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? '';
        $this->category = $data['category'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->price = floatval($data['price'] ?? 0);
        $this->prepTime = intval($data['prep_time'] ?? 0);
        $this->available = (bool)($data['available'] ?? true);
        $this->image = $data['image'] ?? null;
        $this->merchant_id = $data['merchant_id'] ?? null;
        $this->merchant_email = $data['merchant_email'] ?? '';
        $this->approval_status = isset($data['approval_status']) ? trim($data['approval_status']) : 'active';
    }
}

class MenuManager {
    private $db;
    private $merchant_id;

    public function __construct($merchant_id = null) {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->merchant_id = $merchant_id;
    }


    //get all menus
    public function getAllMenus(): array {
        $query = "SELECT m.*, u.email as merchant_email 
                  FROM menu_items m 
                  JOIN users u ON m.merchant_id = u.id 
                  WHERE m.available = 1 AND (COALESCE(m.approval_status, 'active') = 'active')
                  ORDER BY m.category, m.name";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $menus = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $menus[] = new MenuItem($row);
        }
        return $menus;
    }

    //get all menus including unavailable
    public function getAllMenusIncludingUnavailable(): array {
        $query = "SELECT m.*, u.email as merchant_email 
                  FROM menu_items m 
                  JOIN users u ON m.merchant_id = u.id 
                  WHERE (COALESCE(m.approval_status, 'active') = 'active')
                  ORDER BY m.available DESC, m.category, m.name";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $menus = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $menus[] = new MenuItem($row);
        }
        return $menus;
    }

    // Get menus for specific merchant
    public function getMerchantMenus(): array {
        if (!$this->merchant_id) return [];
        $query = "SELECT * FROM menu_items 
                  WHERE merchant_id = :merchant_id 
                  ORDER BY category, name";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':merchant_id', $this->merchant_id);
        $stmt->execute();
        $menus = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $menus[] = new MenuItem($row);
        }
        return $menus;
    }

    //add menu item for current merchant
    public function addMenu($name, $category, $description, $price, $prepTime, $image = null) {
        if (!$this->merchant_id) return false;
        
        $query = "INSERT INTO menu_items (merchant_id, name, category, description, price, prep_time, image) 
                  VALUES (:merchant_id, :name, :category, :description, :price, :prep_time, :image)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':merchant_id', $this->merchant_id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':prep_time', $prepTime);
        $stmt->bindParam(':image', $image);
        
        return $stmt->execute();
    }

    // Update menu item (sets approval_status to 'pending' so admin must re-approve)
    public function updateMenu($id, $name, $category, $description, $price, $prepTime, $image = null) {
        if (!$this->merchant_id) return false;
        $check = $this->db->prepare("SELECT id, image FROM menu_items WHERE id = :id AND merchant_id = :mid");
        $check->bindParam(':id', $id, PDO::PARAM_INT);
        $check->bindParam(':mid', $this->merchant_id, PDO::PARAM_INT);
        $check->execute();
        if ($check->rowCount() === 0) return false;

        $existing = $check->fetch(PDO::FETCH_ASSOC);
        $imageToUse = $image !== null ? $image : $existing['image'];

        $sql = "UPDATE menu_items SET name = :name, category = :category, description = :description, price = :price, prep_time = :prep_time, image = :image WHERE id = :id AND merchant_id = :mid";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':prep_time', $prepTime, PDO::PARAM_INT);
        $stmt->bindParam(':image', $imageToUse);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':mid', $this->merchant_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    //toggle availability
    public function toggleAvailability($id) {
        if (!$this->merchant_id) return false;
        
        //check if this menu belongs to the merchant
        $checkQuery = "SELECT id FROM menu_items WHERE id = :id AND merchant_id = :merchant_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->bindParam(':merchant_id', $this->merchant_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            return false; //not authorized
        }
        
        $query = "UPDATE menu_items SET available = NOT available WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    //delete menu item
    public function deleteMenu($id) {
        if (!$this->merchant_id) return false;
        
        $query = "DELETE FROM menu_items WHERE id = :id AND merchant_id = :merchant_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':merchant_id', $this->merchant_id);
        return $stmt->execute();
    }

    //get menu item by ID
    public function getMenuById($id) {
        $query = "SELECT m.*, u.email as merchant_email 
                  FROM menu_items m 
                  JOIN users u ON m.merchant_id = u.id 
                  WHERE m.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return new MenuItem($row);
        }
        return null;
    }
}
?>