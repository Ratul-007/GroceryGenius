-- GroceryGenius Daily Price History
-- Run this once in phpMyAdmin on grocerygenius_db before using the updated Price Tracker.

CREATE TABLE IF NOT EXISTS price_history (
  history_id    INT AUTO_INCREMENT PRIMARY KEY,
  product_id    INT NOT NULL,
  price_bdt     DECIMAL(10,2) NOT NULL,
  recorded_date DATE NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_product_day (product_id, recorded_date),
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
);
