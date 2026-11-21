CREATE DATABASE IF NOT EXISTS mfc_system;
USE mfc_system;

CREATE TABLE IF NOT EXISTS farmers_table(
  farmer_id VARCHAR(30) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  phone_number VARCHAR(15) UNIQUE NOT NULL,
  location VARCHAR(50),
  farm_size DECIMAL(10,2),
  id_number VARCHAR(20),
  id_front_photo VARCHAR(255),
  id_back_photo VARCHAR(255),
  profile_photo VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS produce_table(
  produce_id VARCHAR(30) PRIMARY KEY,
  farmer_id VARCHAR(30) NOT NULL,
  crop_type VARCHAR(50) NOT NULL,
  quantity_kg DECIMAL(10,2) NOT NULL,
  price_per_kg DECIMAL(10,2) NOT NULL,
  harvest_date DATE,
  FOREIGN KEY (farmer_id) REFERENCES farmers_table(farmer_id)
);

CREATE TABLE IF NOT EXISTS buyers_table(
  buyer_id VARCHAR(30) PRIMARY KEY,
  full_name_or_company_name VARCHAR(100) NOT NULL,
  phone_number VARCHAR(15) UNIQUE NOT NULL,
  buyer_type VARCHAR(50),
  id_number VARCHAR(20),
  id_front_photo VARCHAR(255),
  id_back_photo VARCHAR(255),
  profile_photo VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS orders_table(
  order_id VARCHAR(30) PRIMARY KEY,
  buyer_id VARCHAR(30) NOT NULL,
  produce_id VARCHAR(30) NOT NULL,
  quantity_ordered DECIMAL(10,2) NOT NULL,
  order_date DATE,
  status VARCHAR(20) DEFAULT 'Pending',
  FOREIGN KEY (buyer_id) REFERENCES buyers_table(buyer_id),
  FOREIGN KEY (produce_id) REFERENCES produce_table(produce_id)
);

CREATE TABLE IF NOT EXISTS payment_table(
  payment_id VARCHAR(30) PRIMARY KEY,
  order_id VARCHAR(30) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(20),
  payment_date DATE,
  FOREIGN KEY (order_id) REFERENCES orders_table(order_id)
);

CREATE TABLE IF NOT EXISTS transporters_table(
  transporter_id VARCHAR(30) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  vehicle_number VARCHAR(20) UNIQUE,
  phone_number VARCHAR(15),
  rate_per_km DECIMAL(10,2),
  id_number VARCHAR(20),
  id_front_photo VARCHAR(255),
  id_back_photo VARCHAR(255),
  profile_photo VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS delivery_table(
  delivery_id VARCHAR(30) PRIMARY KEY,
  order_id VARCHAR(30) NOT NULL,
  transporter_id VARCHAR(30) NOT NULL,
  delivery_date DATE,
  delivery_location VARCHAR(100),
  FOREIGN KEY (order_id) REFERENCES orders_table(order_id),
  FOREIGN KEY (transporter_id) REFERENCES transporters_table(transporter_id)
);

CREATE TABLE IF NOT EXISTS market_price_table(
  price_id VARCHAR(30) PRIMARY KEY,
  crop_type VARCHAR(50),
  market_name VARCHAR(100),
  current_price DECIMAL(10,2),
  date_updated DATE
);

CREATE TABLE IF NOT EXISTS admin_staff_table(
  staff_id VARCHAR(30) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  role VARCHAR(50),
  phone_number VARCHAR(15)
);

CREATE TABLE IF NOT EXISTS user_accounts(
  user_id VARCHAR(30) PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('buyer', 'farmer', 'transporter', 'admin') NOT NULL,
  account_status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE user_accounts
  MODIFY role ENUM('buyer', 'farmer', 'transporter', 'admin') NOT NULL;

CREATE TABLE IF NOT EXISTS farmer_applications(
  application_id VARCHAR(30) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  phone_number VARCHAR(15) NOT NULL UNIQUE,
  location VARCHAR(50) NOT NULL,
  farm_size DECIMAL(10,2) NOT NULL,
  id_number VARCHAR(20) NOT NULL,
  id_front_photo VARCHAR(100),
  id_back_photo VARCHAR(100),
  status ENUM('pending', 'approved', 'rejected', 'account_created') NOT NULL DEFAULT 'pending',
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS transporter_applications(
  application_id VARCHAR(30) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  phone_number VARCHAR(15) NOT NULL UNIQUE,
  vehicle_number VARCHAR(20) NOT NULL UNIQUE,
  rate_per_km DECIMAL(10,2) NOT NULL,
  id_number VARCHAR(20) NOT NULL,
  id_front_photo VARCHAR(100),
  id_back_photo VARCHAR(100),
  status ENUM('pending', 'approved', 'rejected', 'account_created') NOT NULL DEFAULT 'pending',
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS products(
  product_id VARCHAR(30) PRIMARY KEY,
  farmer_id VARCHAR(30) NOT NULL,
  title VARCHAR(100) NOT NULL,
  category VARCHAR(50) NOT NULL,
  produce_condition VARCHAR(30) NOT NULL,
  unit_label VARCHAR(30) NOT NULL,
  price_per_unit DECIMAL(10,2) NOT NULL,
  quantity_available DECIMAL(10,2) NOT NULL,
  preferred_payment_method VARCHAR(50) NOT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX products_farmer_created (farmer_id, created_at),
  FOREIGN KEY (farmer_id) REFERENCES farmers_table(farmer_id)
);

SET @has_product_status_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'status'
);
SET @add_product_status_column = IF(
  @has_product_status_column = 0,
  'ALTER TABLE products
     ADD COLUMN status ENUM(''active'', ''inactive'') NOT NULL DEFAULT ''active'' AFTER preferred_payment_method',
  'SELECT 1'
);
PREPARE add_product_status_column_stmt FROM @add_product_status_column;
EXECUTE add_product_status_column_stmt;
DEALLOCATE PREPARE add_product_status_column_stmt;

CREATE TABLE IF NOT EXISTS orders(
  order_id VARCHAR(30) PRIMARY KEY,
  buyer_id VARCHAR(30) NOT NULL,
  farmer_id VARCHAR(30) NOT NULL,
  product_id VARCHAR(30) NOT NULL,
  transporter_id VARCHAR(30) NULL,
  quantity DECIMAL(10,2) NOT NULL,
  produce_subtotal DECIMAL(12,2) NOT NULL,
  platform_commission_5pct DECIMAL(12,2) NOT NULL,
  farmer_net_payout DECIMAL(12,2) NOT NULL,
  order_status ENUM('pending', 'confirmed', 'assigned', 'in_transit', 'completed', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX orders_farmer_created (farmer_id, created_at),
  INDEX orders_buyer_created (buyer_id, created_at),
  INDEX orders_transporter_status (transporter_id, order_status),
  FOREIGN KEY (buyer_id) REFERENCES buyers_table(buyer_id),
  FOREIGN KEY (farmer_id) REFERENCES farmers_table(farmer_id),
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  FOREIGN KEY (transporter_id) REFERENCES transporters_table(transporter_id)
);

SET @has_transporter_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'transporter_id'
);
SET @add_transporter_column = IF(
  @has_transporter_column = 0,
  'ALTER TABLE orders
     ADD COLUMN transporter_id VARCHAR(30) NULL,
     ADD INDEX orders_transporter_status (transporter_id, order_status),
     ADD CONSTRAINT orders_transporter_fk FOREIGN KEY (transporter_id) REFERENCES transporters_table(transporter_id)',
  'SELECT 1'
);
PREPARE add_transporter_column_stmt FROM @add_transporter_column;
EXECUTE add_transporter_column_stmt;
DEALLOCATE PREPARE add_transporter_column_stmt;

ALTER TABLE orders
  MODIFY order_status ENUM('pending', 'confirmed', 'assigned', 'in_transit', 'completed', 'delivered', 'cancelled')
  NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS order_gps_tracking(
  order_id VARCHAR(30) PRIMARY KEY,
  transporter_id VARCHAR(30) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(order_id),
  FOREIGN KEY (transporter_id) REFERENCES transporters_table(transporter_id)
);

CREATE TABLE IF NOT EXISTS user_ratings(
  rating_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(30) NULL,
  rater_id VARCHAR(30) NOT NULL,
  ratee_id VARCHAR(30) NOT NULL,
  stars TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX user_ratings_ratee (ratee_id),
  INDEX user_ratings_order (order_id),
  UNIQUE KEY user_ratings_order_rater_ratee (order_id, rater_id, ratee_id),
  CONSTRAINT user_ratings_order_fk FOREIGN KEY (order_id) REFERENCES orders(order_id),
  FOREIGN KEY (rater_id) REFERENCES user_accounts(user_id),
  FOREIGN KEY (ratee_id) REFERENCES user_accounts(user_id)
);

SET @has_rating_order_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'user_ratings' AND column_name = 'order_id'
);
SET @add_rating_order_column = IF(
  @has_rating_order_column = 0,
  'ALTER TABLE user_ratings
     ADD COLUMN order_id VARCHAR(30) NULL,
     ADD INDEX user_ratings_order (order_id),
     ADD CONSTRAINT user_ratings_order_fk FOREIGN KEY (order_id) REFERENCES orders(order_id)',
  'SELECT 1'
);
PREPARE add_rating_order_column_stmt FROM @add_rating_order_column;
EXECUTE add_rating_order_column_stmt;
DEALLOCATE PREPARE add_rating_order_column_stmt;

SET @has_rating_order_unique = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'user_ratings'
    AND index_name = 'user_ratings_order_rater_ratee'
);
SET @add_rating_order_unique = IF(
  @has_rating_order_unique = 0,
  'ALTER TABLE user_ratings
     ADD UNIQUE KEY user_ratings_order_rater_ratee (order_id, rater_id, ratee_id)',
  'SELECT 1'
);
PREPARE add_rating_order_unique_stmt FROM @add_rating_order_unique;
EXECUTE add_rating_order_unique_stmt;
DEALLOCATE PREPARE add_rating_order_unique_stmt;

CREATE TABLE IF NOT EXISTS order_messages(
  message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(30) NOT NULL,
  sender_id VARCHAR(30) NOT NULL,
  sender_role ENUM('buyer', 'farmer', 'transporter', 'admin') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX order_messages_order_created (order_id, created_at),
  FOREIGN KEY (order_id) REFERENCES orders(order_id),
  FOREIGN KEY (sender_id) REFERENCES user_accounts(user_id)
);

ALTER TABLE order_messages
  MODIFY sender_role ENUM('buyer', 'farmer', 'transporter', 'admin') NOT NULL;

