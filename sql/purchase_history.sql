-- GroceryGenius: Purchase History
-- Creates a permanent record of completed grocery purchases.

CREATE TABLE IF NOT EXISTS purchase_history (
    purchase_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    price_per_unit DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    shopping_list_id INT NULL,

    CONSTRAINT fk_purchase_history_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_purchase_history_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_purchase_history_shopping_list
        FOREIGN KEY (shopping_list_id)
        REFERENCES shopping_list(list_item_id)
        ON DELETE SET NULL
);
