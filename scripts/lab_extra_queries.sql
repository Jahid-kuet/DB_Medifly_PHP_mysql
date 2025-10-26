-- Lab extra queries: HAVING, correlated subquery, UNION, VIEW, BETWEEN
-- Run against mediflydb_php

-- 1) HAVING: count requests per operator and show operators with > 0 requests
SELECT assigned_operator_id AS operator_id, COUNT(*) AS req_count
FROM DeliveryRequests
WHERE assigned_operator_id IS NOT NULL
GROUP BY assigned_operator_id
HAVING req_count > 0
ORDER BY req_count DESC;

-- 2) Correlated subquery: requests that have more than 1 log entry
SELECT dr.request_id, dr.destination, dr.created_at,
  (SELECT COUNT(*) FROM DeliveryLogs dl WHERE dl.request_id = dr.request_id) AS log_count
FROM DeliveryRequests dr
WHERE (SELECT COUNT(*) FROM DeliveryLogs dl WHERE dl.request_id = dr.request_id) > 1
ORDER BY log_count DESC
LIMIT 50;

-- 3) UNION: combine hospital names and drone models into a single list
SELECT name AS label, 'hospital' AS type FROM Hospitals
UNION
SELECT model AS label, 'drone' AS type FROM Drones
ORDER BY type, label
LIMIT 50;

-- 4) VIEW: create a simple request summary per user and show top 10
DROP VIEW IF EXISTS vw_request_summary;
CREATE VIEW vw_request_summary AS
SELECT user_id, COUNT(*) AS total_requests, SUM(payment_amount) AS total_amount
FROM DeliveryRequests
GROUP BY user_id;

SELECT * FROM vw_request_summary ORDER BY total_requests DESC LIMIT 10;

-- 5) BETWEEN (range): recent tracking points in the last 7 days
SELECT tracking_id, request_id, latitude, longitude, recorded_at
FROM DeliveryTracking
WHERE recorded_at BETWEEN DATE_SUB(NOW(), INTERVAL 7 DAY) AND NOW()
ORDER BY recorded_at DESC
LIMIT 50;

-- End of lab queries
