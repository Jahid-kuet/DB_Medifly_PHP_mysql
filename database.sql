-- MediFly Delivery Management Schema
CREATE DATABASE IF NOT EXISTS mediflydb_php CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mediflydb_php;

CREATE TABLE IF NOT EXISTS Hospitals (
    hospital_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(200) NOT NULL
);

CREATE TABLE IF NOT EXISTS Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','hospital','operator') NOT NULL,
    phone VARCHAR(20),
    hospital_id INT NULL,
    FOREIGN KEY (hospital_id) REFERENCES Hospitals(hospital_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS Drones (
    drone_id INT AUTO_INCREMENT PRIMARY KEY,
    model VARCHAR(100),
    capacity INT,
    status ENUM('available','maintenance','assigned') DEFAULT 'available'
);

CREATE TABLE IF NOT EXISTS Supplies (
    supply_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    quantity INT,
    unit_price DECIMAL(10, 2) DEFAULT 0.00
);

CREATE TABLE IF NOT EXISTS DeliveryRequests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    supply_id INT,
    quantity INT DEFAULT 1,
    destination VARCHAR(200),
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    status ENUM('pending','approved','payment-pending','in-transit','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('unpaid','pending','paid','refunded') DEFAULT 'unpaid',
    payment_amount DECIMAL(10, 2) DEFAULT 0.00,
    payment_method VARCHAR(50) NULL,
    operator_id INT NULL,
    drone_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (supply_id) REFERENCES Supplies(supply_id) ON DELETE SET NULL,
    FOREIGN KEY (operator_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (drone_id) REFERENCES Drones(drone_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS DeliveryTracking (
    tracking_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    altitude INT NULL,
    speed DECIMAL(6, 2) NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES DeliveryRequests(request_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS DeliveryLogs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT,
    timestamp DATETIME,
    notes TEXT,
    FOREIGN KEY (request_id) REFERENCES DeliveryRequests(request_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Operators (
    operator_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    drone_id INT,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (drone_id) REFERENCES Drones(drone_id) ON DELETE CASCADE
);

-- Payments table for tracking transactions
CREATE TABLE IF NOT EXISTS Payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50),
    transaction_id VARCHAR(100),
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','completed','failed','refunded') DEFAULT 'pending',
    notes TEXT,
    FOREIGN KEY (request_id) REFERENCES DeliveryRequests(request_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- Insert sample data for Khulna hospitals
INSERT INTO Hospitals (name, location) VALUES
('Khulna Medical College Hospital', 'Khan Jahan Ali Road, Khulna 9100, Bangladesh'),
('Gazi Medical College Hospital', 'Baniakhamar, Khulna 9100, Bangladesh'),
('Islami Bank Community Hospital', 'Sonadanga, Khulna 9100, Bangladesh'),
('Ad-din Barrister Rafique-ul Huq Hospital', 'Shibbari, Khulna 9100, Bangladesh'),
('United Hospital Khulna', 'Shonadanga, Khulna 9100, Bangladesh');

-- Insert sample medical supplies
INSERT INTO Supplies (name, quantity, unit_price) VALUES
('Emergency Medicine Kit', 50, 8500.00),
('Blood Bag (O+)', 100, 1200.00),
('Oxygen Cylinder', 30, 9500.00),
('Surgical Equipment Set', 20, 15000.00),
('COVID-19 Test Kits', 200, 600.00),
('Insulin Vials', 80, 1800.00),
('Antibiotics Pack', 150, 700.00),
('IV Fluid Bags', 120, 350.00),
('Bandages & Gauze', 300, 90.00),
('PPE Kit', 100, 500.00);

-- Insert sample drones
INSERT INTO Drones (model, capacity, status) VALUES
('DJI Matrice 300 RTK', 5, 'available'),
('DJI Matrice 600 Pro', 6, 'available'),
('Freefly Alta X', 8, 'available'),
('Wingcopter 198', 6, 'maintenance'),
('Zipline Z2', 4, 'available');

-- Sample operator accounts (default password: operator123)
INSERT INTO Users (name, username, password, role, phone, hospital_id) VALUES
('Akash Ahmed', 'akash', '$2y$10$qUsNpWgxUnUmshsLj1PLA.y104d4Z1A8.9DTWtVw8HVrE9vKdXMz.', 'operator', '01710000001', NULL),
('Tahmid Karim', 'tahmid', '$2y$10$qUsNpWgxUnUmshsLj1PLA.y104d4Z1A8.9DTWtVw8HVrE9vKdXMz.', 'operator', '01710000002', NULL),
('Rahul Sen', 'rahul', '$2y$10$qUsNpWgxUnUmshsLj1PLA.y104d4Z1A8.9DTWtVw8HVrE9vKdXMz.', 'operator', '01710000003', NULL),
('Naquib Hasan', 'naquib', '$2y$10$qUsNpWgxUnUmshsLj1PLA.y104d4Z1A8.9DTWtVw8HVrE9vKdXMz.', 'operator', '01710000004', NULL);
