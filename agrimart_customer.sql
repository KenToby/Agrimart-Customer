-- =====================================================================
-- DATABASE 2 of 2:  agrimart_customer
-- Used by the customer shop page (folder: agrimart_customer)
--
-- 1. In phpMyAdmin, create a database named  agrimart_customer  and click it.
-- 2. Import tab > choose this file > Go.
--
-- This database is separate from agrimart_admin. It keeps the customers'
-- own order records. The products come from agrimart_admin, and each order
-- is also sent to the farmer there, so the farmer can approve it.
--
-- THE 3 TABLES
--   customer_orders        -> one row per order (who ordered, where, how they pay)
--   customer_order_items   -> the products inside each order
--   customer_farmer_orders -> which farmer order(s) in agrimart_admin this order became
--
-- RELATIONSHIPS
--   customer_orders 1---* customer_order_items
--   customer_orders 1---* customer_farmer_orders
--   customer_farmer_orders.farmer_order_id points to agrimart_admin.orders.id
--   (a plain reference, not a real foreign key, because it is another database)
-- =====================================================================


CREATE TABLE customer_orders (
  id            INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Row number, given automatically',
  order_code    VARCHAR(20)   NULL UNIQUE COMMENT 'Order number shown to the customer, for example SF-100001',
  access_token  CHAR(16)      NOT NULL    COMMENT 'Random secret kept in the customer browser. Needed to view this order, so nobody can look at other orders by guessing order numbers',
  customer_name VARCHAR(120)  NOT NULL    COMMENT 'Name of the customer',
  phone         VARCHAR(30)   NOT NULL    COMMENT 'Customer phone number',
  address       VARCHAR(255)  NOT NULL    COMMENT 'Where to deliver the order',
  pay_method    ENUM('COD','GCash') NOT NULL DEFAULT 'COD' COMMENT 'COD = Cash on Delivery, GCash = paid by GCash',
  subtotal      DECIMAL(10,2) NOT NULL    COMMENT 'Total of the items before the delivery fee',
  delivery_fee  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Delivery fee in pesos. 0 when the subtotal is 500 or more',
  total         DECIMAL(10,2) NOT NULL    COMMENT 'Subtotal plus delivery fee = amount to pay',
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the order was placed',
  INDEX idx_custorders_phone (phone)
) ENGINE=InnoDB COMMENT='Orders placed on the customer shop page. The status is not kept here: it comes from the farmer order in agrimart_admin';

CREATE TABLE customer_order_items (
  id             INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Row number, given automatically',
  order_id       INT           NOT NULL    COMMENT 'Order this item belongs to. Links to customer_orders',
  product_id     VARCHAR(20)   NOT NULL    COMMENT 'Product id. p-xxxx = a farmer product from agrimart_admin, a plain number = a built-in sample product',
  name           VARCHAR(120)  NOT NULL    COMMENT 'Product name at the time of ordering',
  icon           VARCHAR(10)   NULL        COMMENT 'Emoji of the product',
  unit           VARCHAR(20)   NOT NULL    COMMENT 'Unit of the product, for example kilo',
  price          DECIMAL(10,2) NOT NULL    COMMENT 'Price of one unit at the time of ordering',
  qty            INT           NOT NULL    COMMENT 'How many units were ordered',
  sent_to_farmer TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = a real farmer product, sent to the farmer. 0 = a sample product, not sent',
  CONSTRAINT fk_custitem_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
  CONSTRAINT chk_custitem_qty CHECK (qty > 0)
) ENGINE=InnoDB COMMENT='The products inside each customer order (one row per product)';

CREATE TABLE customer_farmer_orders (
  id              INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Row number, given automatically',
  order_id        INT         NOT NULL    COMMENT 'Customer order. Links to customer_orders',
  farmer_order_id VARCHAR(20) NOT NULL UNIQUE COMMENT 'Matching order in agrimart_admin.orders (one per farmer, for example SF-100001 or SF-100001-A). Not a real foreign key, it is another database',
  CONSTRAINT fk_cfo_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Links a customer order to the order(s) the farmers received. Used to show the order status to the customer';
