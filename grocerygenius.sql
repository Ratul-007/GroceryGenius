-- GroceryGenius Database Schema
-- Team: 404 Team Not Found

CREATE DATABASE IF NOT EXISTS grocerygenius_db;
USE grocerygenius_db;

CREATE TABLE users (
  user_id    INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  email      VARCHAR(150) UNIQUE NOT NULL,
  password   VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
  product_id INT AUTO_INCREMENT PRIMARY KEY,
  barcode    VARCHAR(50),
  name       VARCHAR(200) NOT NULL,
  category   VARCHAR(100),
  unit       VARCHAR(50)
);

CREATE TABLE pantry_items (
  item_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT,
  product_id  INT,
  quantity    DECIMAL(8,2),
  expiry_date DATE,
  added_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE shopping_list (
  list_item_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT,
  product_id      INT,
  quantity        DECIMAL(8,2),
  is_purchased    TINYINT DEFAULT 0,
  purchase_amount DECIMAL(10,2) NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE budget (
  budget_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT,
  month        VARCHAR(7),
  limit_amount DECIMAL(10,2),
  spent_amount DECIMAL(10,2) DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Current/latest price for each product.
CREATE TABLE grocery_prices (
  price_id    INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  price_bdt   DECIMAL(10,2) NOT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  INDEX idx_grocery_prices_product (product_id),
  INDEX idx_grocery_prices_updated (updated_at)
);

-- Historical price changes used by the Price Tracker and 7-day trend charts.
-- A product can have multiple records, including multiple changes on the same day.
CREATE TABLE price_history (
  history_id   INT AUTO_INCREMENT PRIMARY KEY,
  product_id   INT NOT NULL,
  price_bdt    DECIMAL(10,2) NOT NULL,
  recorded_date DATE NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  INDEX idx_price_history_product_date (product_id, recorded_date),
  INDEX idx_price_history_recorded_date (recorded_date)
);
