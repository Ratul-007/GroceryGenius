-- GroceryGenius: Shopping List -> Budget integration
-- Run this once in phpMyAdmin on grocerygenius_db.
-- Stores the actual tracked price used when a shopping item is marked purchased.

ALTER TABLE shopping_list
    ADD COLUMN purchase_amount DECIMAL(10,2) NULL AFTER is_purchased;
