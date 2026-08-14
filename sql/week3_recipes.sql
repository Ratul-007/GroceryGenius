-- GroceryGenius Database Schema (Updated Week 3)
-- Team: 404 Team Not Found

-- Run this in phpMyAdmin SQL tab on grocerygenius_db

CREATE TABLE IF NOT EXISTS recipes (
  recipe_id   INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(200) NOT NULL,
  description TEXT,
  prep_time   INT DEFAULT 0,
  cook_time   INT DEFAULT 0,
  servings    INT DEFAULT 2
);

CREATE TABLE IF NOT EXISTS recipe_ingredients (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  recipe_id  INT NOT NULL,
  product_id INT NOT NULL,
  quantity   DECIMAL(8,2),
  unit       VARCHAR(50),
  FOREIGN KEY (recipe_id)  REFERENCES recipes(recipe_id),
  FOREIGN KEY (product_id) REFERENCES products(product_id)
);
