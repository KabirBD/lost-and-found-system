-- Create database and use it (run once)
CREATE DATABASE IF NOT EXISTS lost_and_found;

USE lost_and_found;

-- -------------------------
-- Photos 
-- -------------------------
CREATE TABLE
  photos (
    photo_id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  );

-- -------------------------
-- Users
-- -------------------------
CREATE TABLE
  users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    photo_id INT NULL,
    house_no VARCHAR(20),
    street VARCHAR(100),
    city VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (photo_id) REFERENCES photos (photo_id) ON DELETE SET NULL
  );

-- -------------------------
-- Admins (Role of users)
-- -------------------------
CREATE TABLE
  admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
  );

-- -------------------------
-- Categories
-- -------------------------
CREATE TABLE
  categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
  );

-- -------------------------
-- Locations (superclass)
-- -------------------------
CREATE TABLE
  locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    location_details TEXT
  );

-- Specializations of Location
CREATE TABLE
  places (
    location_id INT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations (location_id) ON DELETE CASCADE
  );

CREATE TABLE
  classrooms (
    location_id INT PRIMARY KEY,
    room_no VARCHAR(20) NOT NULL,
    floor INT,
    building VARCHAR(100),
    FOREIGN KEY (location_id) REFERENCES locations (location_id) ON DELETE CASCADE
  );

CREATE TABLE
  shops (
    location_id INT PRIMARY KEY,
    shop_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations (location_id) ON DELETE CASCADE
  );

CREATE TABLE
  gates (
    location_id INT PRIMARY KEY,
    gate_no VARCHAR(20) NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations (location_id) ON DELETE CASCADE
  );

-- -------------------------
-- Items
-- -------------------------
CREATE TABLE
  items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    status ENUM ('lost', 'found', 'claimed') DEFAULT 'lost',
    user_id INT NOT NULL,
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    admin_id INT NULL,
    approved_at TIMESTAMP NULL,
    location_id INT NULL,
    photo_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins (admin_id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES locations (location_id) ON DELETE SET NULL,
    FOREIGN KEY (photo_id) REFERENCES photos (photo_id) ON DELETE SET NULL
  );

-- -------------------------
-- Many-to-many: item <-> category
-- -------------------------
CREATE TABLE
  item_categories (
    item_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (item_id, category_id),
    FOREIGN KEY (item_id) REFERENCES items (item_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories (category_id) ON DELETE CASCADE
  );

-- -------------------------
-- Claim Approval (ternary relationship)
-- -------------------------
CREATE TABLE
  claim_approvals (
    claim_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    admin_id INT NULL,
    statement TEXT,
    claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    FOREIGN KEY (item_id) REFERENCES items (item_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins (admin_id) ON DELETE SET NULL
  );

-- -------------------------
-- Notifications
-- -------------------------
CREATE TABLE
  notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    admin_id INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    received_at TIMESTAMP NULL,
    FOREIGN KEY (admin_id) REFERENCES admins (admin_id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
  );

-- -------------------------
-- Multi-valued attributes: user emails and phones
-- -------------------------
CREATE TABLE
  user_emails (
    user_id INT NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    PRIMARY KEY (user_id, email),
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_email (email)
  );

CREATE TABLE
  user_phones (
    user_id INT NOT NULL,
    phone VARCHAR(50) NOT NULL,
    PRIMARY KEY (user_id, phone),
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
  );

-- -------------------------
-- Notes (Weak entity - depends on items)
-- -------------------------
CREATE TABLE
  notes (
    item_id INT NOT NULL,
    note_no INT NOT NULL,
    user_id INT NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, note_no),
    FOREIGN KEY (item_id) REFERENCES items (item_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
  );
