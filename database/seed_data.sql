USE eleguard_rail;

-- --------------------------------------------------
-- Seed users (passwords are hashed values of: admin123, control123, driver123, wildlife123, reporter123)
-- --------------------------------------------------
INSERT INTO users (id, name, email, password, role, status) VALUES
(1, 'System Admin', 'admin@eleguard.lk', '$2y$10$8Q6/Jq7tV0sDFeuXWlM/E.2zEqR.rL/J3a5mD7H.F5T.zW6B4D8Y.', 'admin', 'active'),
(2, 'Railway Controller', 'control@eleguard.lk', '$2y$10$eE1Y.q6Z.s8bU.aP1wX7QOp/G0N.N0YF6pC8uN3F7r.W4wB4D8Y.', 'control_officer', 'active'),
(3, 'Train Driver A', 'driver@eleguard.lk', '$2y$10$gM3q.s7c.u8eW.bP2xY8ROq/H1O.O1ZG7qD9vO4G8s.X5xB4D8Y.', 'driver', 'active'),
(4, 'Wildlife Ranger', 'wildlife@eleguard.lk', '$2y$10$iO5s.u8e.w0gY.dP3zZ9TQr/J2P.P2aH8rE0wP5H9t.Y6yB4D8Y.', 'wildlife_officer', 'active'),
(5, 'Villager Reporter', 'reporter@eleguard.lk', '$2y$10$kQ7t.w0g.y2iA.fR4aA0USr/K3Q.Q3bI9sF1xQ6I0u.Z7zB4D8Y.', 'reporter', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- --------------------------------------------------
-- Seed railway_sections
-- --------------------------------------------------
INSERT INTO railway_sections (id, section_name, district, start_location, end_location, risk_level, description) VALUES
(1, 'Habarana - Gal Oya', 'Anuradhapura/Polonnaruwa', 'Habarana Station', 'Gal Oya Junction', 'Critical', 'Highly active elephant crossing corridor with dense jungle cover and several blind curves.'),
(2, 'Minneriya - Hingurakgoda', 'Polonnaruwa', 'Minneriya Station', 'Hingurakgoda Station', 'High', 'Near Minneriya National Park reservoir. Frequent elephant movement during dry season (July-October).'),
(3, 'Maho - Anuradhapura', 'Kurunegala/Anuradhapura', 'Maho Junction', 'Anuradhapura Station', 'Medium', 'Flat plains with scattered rural farmlands. Elephants occasionally cross for water.'),
(4, 'Batticaloa Line Forest Section', 'Batticaloa', 'Welikanda Station', 'Valachchenai Station', 'High', 'Traverses through national sanctuary borders. Multiple elephant herds roam nearby.')
ON DUPLICATE KEY UPDATE section_name=VALUES(section_name);

-- --------------------------------------------------
-- Seed hotspots
-- --------------------------------------------------
INSERT INTO hotspots (id, section_id, hotspot_name, latitude, longitude, risk_level, reason) VALUES
(1, 2, 'Minneriya Elephant Crossing', 8.04120000, 80.82560000, 'Critical', 'Elephants crossing from national park to reservoir during seasonal migrations.'),
(2, 1, 'Habarana Forest Edge', 8.03540000, 80.75110000, 'High', 'Thick vegetation close to tracks limiting driver visibility to less than 50 meters.'),
(3, 1, 'Gal Oya Corridor', 8.12560000, 80.91240000, 'High', 'Active path linking two major forest reserves.'),
(4, 3, 'Night Movement Zone 01', 7.98540000, 80.32140000, 'Medium', 'Water bodies on south side force night-time crossings during dry periods.')
ON DUPLICATE KEY UPDATE hotspot_name=VALUES(hotspot_name);

-- --------------------------------------------------
-- Seed elephant_reports
-- --------------------------------------------------
INSERT INTO elephant_reports (id, section_id, reported_by, elephant_count, location_description, urgency_level, risk_score, report_status, description, image_path, created_at) VALUES
(1, 3, 5, 2, 'Near Mile Post 85, close to the small lake', 'Medium', 'Medium', 'Pending', 'Two adult elephants spotted drinking water near the tracks.', NULL, NOW() - INTERVAL 1 HOUR),
(2, 2, 4, 5, 'Minneriya Crossing marker 102', 'High', 'High', 'Verified', 'Herd of 5 elephants including a calf moving slowly towards the railway track.', NULL, NOW() - INTERVAL 2 HOUR),
(3, 1, 5, 1, 'Habarana bend near forest temple', 'Critical', 'Critical', 'Converted to Alert', 'Large bull elephant standing directly on the tracks.', NULL, NOW() - INTERVAL 30 MINUTE)
ON DUPLICATE KEY UPDATE location_description=VALUES(location_description);

-- --------------------------------------------------
-- Seed alerts
-- --------------------------------------------------
INSERT INTO alerts (id, report_id, section_id, alert_level, alert_message, alert_status, created_by, created_at, resolved_at) VALUES
(1, 3, 1, 'Critical', 'COLLISION RISK: Large bull elephant reported directly on the track at Habarana bend near forest temple.', 'Active', 2, NOW() - INTERVAL 25 MINUTE, NULL),
(2, NULL, 2, 'High', 'SIGHTING WARNING: Elephant movement detected near Minneriya crossing marker 102. Reduce speed and watch tracks.', 'Active', 2, NOW() - INTERVAL 1 HOUR, NULL),
(3, NULL, 4, 'Low', 'PRECAUTIONARY ALERT: General elephant movement reported in Batticaloa Line Forest Section. Be vigilant.', 'Resolved', 2, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 3 HOUR)
ON DUPLICATE KEY UPDATE alert_message=VALUES(alert_message);

-- --------------------------------------------------
-- Seed driver_acknowledgements
-- --------------------------------------------------
INSERT INTO driver_acknowledgements (id, alert_id, driver_id, action_status, note, acknowledged_at) VALUES
(1, 1, 3, 'Slowing Down', 'Proceeding with caution under 15km/h. Alert logged.', NOW() - INTERVAL 15 MINUTE),
(2, 3, 3, 'Acknowledged', 'Noted. Section clear upon transit.', NOW() - INTERVAL 4 HOUR)
ON DUPLICATE KEY UPDATE note=VALUES(note);

-- --------------------------------------------------
-- Seed incident_history
-- --------------------------------------------------
INSERT INTO incident_history (id, section_id, incident_type, description, incident_date, severity, created_at) VALUES
(1, 1, 'Elephant Sighting Collision', 'Udayadevi Express collided with a mature elephant cow. Severe engine damage, elephant passed away.', '2026-03-12', 'Critical', NOW() - INTERVAL 90 DAY),
(2, 2, 'Near Miss Sighting', 'Night mail train managed to stop 10 meters before a herd of 3 elephants after being warned by forest guard torches.', '2026-05-02', 'Medium', NOW() - INTERVAL 50 DAY),
(3, 3, 'False Alarm Sighting', 'Report of an elephant on the tracks turned out to be a large water buffalo grazing near the fence.', '2026-06-10', 'Low', NOW() - INTERVAL 11 DAY)
ON DUPLICATE KEY UPDATE incident_type=VALUES(incident_type);

-- --------------------------------------------------
-- Seed system_logs
-- --------------------------------------------------
INSERT INTO system_logs (id, user_id, action, description, created_at) VALUES
(1, 1, 'Database Init', 'Initial database schema and seed data loaded successfully.', NOW() - INTERVAL 10 DAY),
(2, 5, 'User Login', 'Reporter successfully logged in from Web UI.', NOW() - INTERVAL 1 HOUR),
(3, 4, 'Report Verified', 'Wildlife Officer verified elephant report #2.', NOW() - INTERVAL 50 MINUTE),
(4, 2, 'Alert Created', 'Railway Controller converted report #3 into Critical Alert #1.', NOW() - INTERVAL 25 MINUTE)
ON DUPLICATE KEY UPDATE action=VALUES(action);
