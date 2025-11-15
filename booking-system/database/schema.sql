-- =====================================================
-- BOOKING SYSTEM DATABASE SCHEMA
-- Daily Rental Guest Registration System
-- Based on Integram database structure
-- =====================================================

-- Database configuration
-- Use UTF-8 encoding for Russian language support
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =====================================================
-- CORE TABLES
-- =====================================================

-- Table: properties
-- Stores rental property information
CREATE TABLE IF NOT EXISTS properties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address VARCHAR(500),
    property_type ENUM('apartment', 'house', 'room', 'villa', 'studio') DEFAULT 'apartment',
    max_guests INT DEFAULT 2,
    bedrooms INT DEFAULT 1,
    bathrooms INT DEFAULT 1,
    square_meters DECIMAL(10,2),
    amenities JSON COMMENT 'List of amenities (wifi, parking, kitchen, etc.)',
    images JSON COMMENT 'Array of image URLs',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_property_type (property_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: guests
-- Stores guest information for registration
CREATE TABLE IF NOT EXISTS guests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    passport_series VARCHAR(20),
    passport_number VARCHAR(20),
    passport_issued_by VARCHAR(500),
    passport_issued_date DATE,
    date_of_birth DATE,
    citizenship VARCHAR(100),
    address TEXT,
    telegram_id BIGINT UNIQUE COMMENT 'Telegram user ID for integration',
    telegram_username VARCHAR(255),
    language_code VARCHAR(10) DEFAULT 'ru',
    notes TEXT,
    is_blacklisted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_telegram (telegram_id),
    INDEX idx_blacklist (is_blacklisted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bookings
-- Main booking records
CREATE TABLE IF NOT EXISTS bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    property_id INT NOT NULL,
    guest_id INT NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    number_of_guests INT DEFAULT 1,
    number_of_adults INT DEFAULT 1,
    number_of_children INT DEFAULT 0,
    base_price DECIMAL(10,2) NOT NULL COMMENT 'Base price before adjustments',
    total_price DECIMAL(10,2) NOT NULL COMMENT 'Final price after all adjustments',
    currency VARCHAR(3) DEFAULT 'RUB',
    status ENUM('pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show') DEFAULT 'pending',
    payment_status ENUM('unpaid', 'partial', 'paid', 'refunded') DEFAULT 'unpaid',
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    special_requests TEXT,
    cancellation_reason TEXT,
    cancelled_at TIMESTAMP NULL,
    confirmed_at TIMESTAMP NULL,
    checked_in_at TIMESTAMP NULL,
    checked_out_at TIMESTAMP NULL,
    created_by INT COMMENT 'User ID who created the booking',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE RESTRICT,
    INDEX idx_property (property_id),
    INDEX idx_guest (guest_id),
    INDEX idx_dates (check_in, check_out),
    INDEX idx_status (status),
    INDEX idx_payment_status (payment_status),
    INDEX idx_created_at (created_at),
    CONSTRAINT chk_dates CHECK (check_out > check_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: pricing_rules
-- Dynamic pricing configuration
CREATE TABLE IF NOT EXISTS pricing_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    property_id INT,
    rule_name VARCHAR(255) NOT NULL,
    rule_type ENUM('base', 'weekend', 'holiday', 'seasonal', 'length_of_stay', 'last_minute', 'early_bird') NOT NULL,
    priority INT DEFAULT 0 COMMENT 'Higher priority rules applied first',
    price_per_night DECIMAL(10,2),
    adjustment_type ENUM('fixed', 'percentage', 'multiplier') DEFAULT 'fixed',
    adjustment_value DECIMAL(10,2) COMMENT 'Amount, percentage, or multiplier',
    start_date DATE,
    end_date DATE,
    days_of_week SET('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'),
    min_stay_nights INT COMMENT 'Minimum nights for this rule',
    max_stay_nights INT COMMENT 'Maximum nights for this rule',
    min_days_advance INT COMMENT 'Minimum days in advance for booking',
    max_days_advance INT COMMENT 'Maximum days in advance for booking',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property_active (property_id, is_active),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_priority (priority DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: booking_restrictions
-- Booking rules and restrictions
CREATE TABLE IF NOT EXISTS booking_restrictions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    property_id INT,
    restriction_type ENUM('min_stay', 'max_stay', 'blackout', 'max_guests', 'advance_booking', 'check_in_days', 'check_out_days') NOT NULL,
    restriction_name VARCHAR(255) NOT NULL,
    int_value INT COMMENT 'For numeric restrictions',
    start_date DATE,
    end_date DATE,
    days_of_week SET('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'),
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property_active (property_id, is_active),
    INDEX idx_type (restriction_type),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: booking_price_breakdown
-- Detailed price calculation for each booking
CREATE TABLE IF NOT EXISTS booking_price_breakdown (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    line_item_type ENUM('base_price', 'weekend_surcharge', 'holiday_surcharge', 'seasonal_adjustment', 'length_discount', 'cleaning_fee', 'service_fee', 'tax', 'other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1 COMMENT 'Number of nights or units',
    unit_price DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    is_taxable BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    INDEX idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: payments
-- Payment transactions
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    payment_method ENUM('cash', 'card', 'bank_transfer', 'online', 'other') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'RUB',
    transaction_id VARCHAR(255) COMMENT 'External payment system transaction ID',
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    processed_by INT COMMENT 'User ID who processed payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
    INDEX idx_booking (booking_id),
    INDEX idx_status (payment_status),
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- LOGGING AND AUDIT TABLES
-- =====================================================

-- Table: booking_audit_log
-- Comprehensive audit trail for all booking changes
CREATE TABLE IF NOT EXISTS booking_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT,
    action ENUM('create', 'update', 'delete', 'status_change', 'payment', 'check_in', 'check_out', 'cancel') NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'bookings, guests, properties, etc.',
    entity_id INT NOT NULL,
    old_values JSON COMMENT 'Previous values before change',
    new_values JSON COMMENT 'New values after change',
    changed_fields JSON COMMENT 'List of fields that changed',
    user_id INT COMMENT 'User who made the change',
    user_ip VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    INDEX idx_booking (booking_id),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_action (action),
    INDEX idx_timestamp (timestamp DESC),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: system_log
-- General system activity log
CREATE TABLE IF NOT EXISTS system_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    log_level ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'info',
    category VARCHAR(50) NOT NULL COMMENT 'api, database, pricing, email, etc.',
    message TEXT NOT NULL,
    context JSON COMMENT 'Additional context data',
    user_id INT,
    ip_address VARCHAR(45),
    request_uri VARCHAR(500),
    request_method VARCHAR(10),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (log_level),
    INDEX idx_category (category),
    INDEX idx_timestamp (timestamp DESC),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INTEGRATION TABLES (Telegram, Integram)
-- =====================================================

-- Table: telegram_notifications
-- Queue for Telegram notifications
CREATE TABLE IF NOT EXISTS telegram_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT,
    telegram_id BIGINT NOT NULL,
    notification_type ENUM('booking_confirmed', 'payment_received', 'check_in_reminder', 'check_out_reminder', 'cancellation', 'custom') NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    error_message TEXT,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_telegram (telegram_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: integram_sync
-- Synchronization with Integram database
CREATE TABLE IF NOT EXISTS integram_sync (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL COMMENT 'guest, booking, property',
    local_id INT NOT NULL,
    integram_id INT COMMENT 'ID in Integram system',
    integram_database VARCHAR(100) COMMENT 'Integram database name',
    sync_status ENUM('pending', 'synced', 'failed', 'conflict') DEFAULT 'pending',
    last_sync_at TIMESTAMP NULL,
    sync_data JSON COMMENT 'Data sent/received during sync',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_entity (entity_type, local_id),
    INDEX idx_integram (integram_database, integram_id),
    INDEX idx_status (sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- USERS AND AUTHENTICATION (simplified)
-- =====================================================

-- Table: users
-- System users (staff, admins)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role ENUM('admin', 'manager', 'staff', 'viewer') DEFAULT 'staff',
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_sessions
-- Active user sessions
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- VIEWS FOR COMMON QUERIES
-- =====================================================

-- View: active_bookings
-- Current and upcoming bookings
CREATE OR REPLACE VIEW active_bookings AS
SELECT
    b.*,
    p.name as property_name,
    p.address as property_address,
    CONCAT(g.first_name, ' ', g.last_name) as guest_name,
    g.email as guest_email,
    g.phone as guest_phone,
    DATEDIFF(b.check_out, b.check_in) as nights,
    DATEDIFF(b.check_in, CURDATE()) as days_until_checkin
FROM bookings b
INNER JOIN properties p ON b.property_id = p.id
INNER JOIN guests g ON b.guest_id = g.id
WHERE b.status IN ('confirmed', 'checked_in')
    AND b.check_out >= CURDATE()
ORDER BY b.check_in;

-- View: property_availability
-- Property availability summary
CREATE OR REPLACE VIEW property_availability AS
SELECT
    p.id as property_id,
    p.name as property_name,
    p.is_active,
    COUNT(CASE WHEN b.status IN ('confirmed', 'checked_in') THEN 1 END) as active_bookings,
    MIN(CASE WHEN b.status IN ('confirmed', 'checked_in') AND b.check_in > CURDATE() THEN b.check_in END) as next_checkin,
    MAX(CASE WHEN b.status = 'checked_in' THEN b.check_out END) as current_checkout
FROM properties p
LEFT JOIN bookings b ON p.id = b.property_id
GROUP BY p.id, p.name, p.is_active;

-- View: booking_revenue_summary
-- Revenue analytics
CREATE OR REPLACE VIEW booking_revenue_summary AS
SELECT
    DATE_FORMAT(b.check_in, '%Y-%m') as month,
    p.id as property_id,
    p.name as property_name,
    COUNT(*) as total_bookings,
    SUM(CASE WHEN b.status = 'confirmed' OR b.status = 'checked_out' THEN 1 ELSE 0 END) as completed_bookings,
    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
    SUM(b.total_price) as total_revenue,
    SUM(b.paid_amount) as collected_revenue,
    AVG(b.total_price) as average_booking_value,
    AVG(DATEDIFF(b.check_out, b.check_in)) as average_nights
FROM bookings b
INNER JOIN properties p ON b.property_id = p.id
GROUP BY DATE_FORMAT(b.check_in, '%Y-%m'), p.id, p.name
ORDER BY month DESC, property_name;

-- =====================================================
-- INITIAL DATA / EXAMPLES
-- =====================================================

-- Insert default admin user (password: admin123 - CHANGE IN PRODUCTION!)
INSERT INTO users (username, email, password_hash, first_name, last_name, role) VALUES
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'admin');

-- Insert sample property
INSERT INTO properties (name, description, address, property_type, max_guests, bedrooms, bathrooms, square_meters, amenities, is_active) VALUES
('Уютная квартира в центре', 'Современная однокомнатная квартира с ремонтом в центре города', 'г. Москва, ул. Тверская, д. 1', 'apartment', 2, 1, 1, 45.00,
'["wifi", "kitchen", "washing_machine", "air_conditioning", "tv"]', TRUE);

-- Insert base pricing rule
INSERT INTO pricing_rules (property_id, rule_name, rule_type, priority, price_per_night, is_active) VALUES
(1, 'Базовая цена', 'base', 0, 3000.00, TRUE);

-- Insert weekend pricing rule
INSERT INTO pricing_rules (property_id, rule_name, rule_type, priority, adjustment_type, adjustment_value, days_of_week, is_active) VALUES
(1, 'Наценка выходные', 'weekend', 10, 'percentage', 20.00, 'saturday,sunday', TRUE);

-- Insert minimum stay restriction
INSERT INTO booking_restrictions (property_id, restriction_type, restriction_name, int_value, is_active) VALUES
(1, 'min_stay', 'Минимум 2 ночи', 2, TRUE);

-- =====================================================
-- STORED PROCEDURES AND FUNCTIONS
-- =====================================================

DELIMITER //

-- Function: calculate_booking_price
-- Calculate total price for a booking with dynamic pricing
CREATE FUNCTION calculate_booking_price(
    p_property_id INT,
    p_check_in DATE,
    p_check_out DATE
) RETURNS DECIMAL(10,2)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_total_price DECIMAL(10,2) DEFAULT 0;
    DECLARE v_base_price DECIMAL(10,2);
    DECLARE v_current_date DATE;
    DECLARE v_nights INT;

    SET v_nights = DATEDIFF(p_check_out, p_check_in);

    -- Get base price
    SELECT price_per_night INTO v_base_price
    FROM pricing_rules
    WHERE property_id = p_property_id
        AND rule_type = 'base'
        AND is_active = TRUE
    LIMIT 1;

    IF v_base_price IS NULL THEN
        SET v_base_price = 0;
    END IF;

    -- Calculate price for each night
    SET v_current_date = p_check_in;
    WHILE v_current_date < p_check_out DO
        SET v_total_price = v_total_price + v_base_price;
        -- Note: Additional pricing rules would be applied here
        SET v_current_date = DATE_ADD(v_current_date, INTERVAL 1 DAY);
    END WHILE;

    RETURN v_total_price;
END//

-- Procedure: check_booking_availability
-- Check if property is available for given dates
CREATE PROCEDURE check_booking_availability(
    IN p_property_id INT,
    IN p_check_in DATE,
    IN p_check_out DATE,
    OUT p_is_available BOOLEAN,
    OUT p_conflict_booking_id INT
)
BEGIN
    DECLARE v_conflict_count INT DEFAULT 0;

    -- Check for overlapping bookings
    SELECT COUNT(*), MAX(id) INTO v_conflict_count, p_conflict_booking_id
    FROM bookings
    WHERE property_id = p_property_id
        AND status IN ('confirmed', 'checked_in', 'pending')
        AND NOT (check_out <= p_check_in OR check_in >= p_check_out);

    SET p_is_available = (v_conflict_count = 0);
END//

DELIMITER ;

-- =====================================================
-- TRIGGERS
-- =====================================================

DELIMITER //

-- Trigger: log_booking_changes
-- Automatically log all booking modifications
CREATE TRIGGER log_booking_insert AFTER INSERT ON bookings
FOR EACH ROW
BEGIN
    INSERT INTO booking_audit_log (booking_id, action, entity_type, entity_id, new_values, timestamp)
    VALUES (NEW.id, 'create', 'bookings', NEW.id,
        JSON_OBJECT(
            'property_id', NEW.property_id,
            'guest_id', NEW.guest_id,
            'check_in', NEW.check_in,
            'check_out', NEW.check_out,
            'total_price', NEW.total_price,
            'status', NEW.status
        ),
        NOW()
    );
END//

CREATE TRIGGER log_booking_update AFTER UPDATE ON bookings
FOR EACH ROW
BEGIN
    INSERT INTO booking_audit_log (booking_id, action, entity_type, entity_id, old_values, new_values, timestamp)
    VALUES (NEW.id, 'update', 'bookings', NEW.id,
        JSON_OBJECT(
            'status', OLD.status,
            'payment_status', OLD.payment_status,
            'total_price', OLD.total_price
        ),
        JSON_OBJECT(
            'status', NEW.status,
            'payment_status', NEW.payment_status,
            'total_price', NEW.total_price
        ),
        NOW()
    );
END//

-- Trigger: update_booking_payment_status
-- Automatically update booking payment status based on payments
CREATE TRIGGER update_payment_status AFTER INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE v_total_paid DECIMAL(10,2);
    DECLARE v_booking_total DECIMAL(10,2);

    SELECT SUM(amount) INTO v_total_paid
    FROM payments
    WHERE booking_id = NEW.booking_id AND payment_status = 'completed';

    SELECT total_price INTO v_booking_total
    FROM bookings
    WHERE id = NEW.booking_id;

    IF v_total_paid >= v_booking_total THEN
        UPDATE bookings SET payment_status = 'paid', paid_amount = v_total_paid WHERE id = NEW.booking_id;
    ELSEIF v_total_paid > 0 THEN
        UPDATE bookings SET payment_status = 'partial', paid_amount = v_total_paid WHERE id = NEW.booking_id;
    END IF;
END//

DELIMITER ;

-- =====================================================
-- INDEXES FOR PERFORMANCE OPTIMIZATION
-- =====================================================

-- Additional composite indexes for common queries
CREATE INDEX idx_bookings_property_dates ON bookings(property_id, check_in, check_out, status);
CREATE INDEX idx_bookings_guest_status ON bookings(guest_id, status);
CREATE INDEX idx_pricing_property_dates ON pricing_rules(property_id, start_date, end_date, is_active);

-- =====================================================
-- DATABASE VERSION TRACKING
-- =====================================================

CREATE TABLE IF NOT EXISTS schema_version (
    version INT PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version, description) VALUES
(1, 'Initial schema - Daily rental booking system with Integram integration');

-- End of schema
