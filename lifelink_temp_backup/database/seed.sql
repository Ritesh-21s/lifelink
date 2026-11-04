-- LifeLink Seed Data
-- Realistic sample data: 40+ donors, 8 blood groups, 6+ areas, 15 requests, 10 donations, 5 camps
-- Passwords for all demo accounts: Password@123  (hash generated with PHP password_hash)

USE lifelink;

-- ============================================================
-- SETTINGS
-- ============================================================
INSERT INTO settings (`key`, `value`) VALUES
('male_donation_gap_days',   '90'),
('female_donation_gap_days', '120'),
('shortage_threshold',       '3'),
('max_open_requests_per_day','3'),
('escalation_minutes',       '30'),
('site_name',                'LifeLink'),
('site_email',               'admin@lifelink.local'),
('lives_multiplier',         '3')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ============================================================
-- AREAS (6 areas in Chennai + adjacency)
-- ============================================================
INSERT INTO areas (id, name, city) VALUES
(1, 'Anna Nagar',    'Chennai'),
(2, 'Adyar',         'Chennai'),
(3, 'T. Nagar',      'Chennai'),
(4, 'Velachery',     'Chennai'),
(5, 'Tambaram',      'Chennai'),
(6, 'Porur',         'Chennai'),
(7, 'Mylapore',      'Chennai'),
(8, 'Chromepet',     'Chennai');

-- adjacency (bidirectional)
INSERT INTO area_adjacency (area_id, adjacent_area_id) VALUES
(1,3),(3,1),
(1,6),(6,1),
(2,7),(7,2),
(2,4),(4,2),
(3,7),(7,3),
(3,4),(4,3),
(4,5),(5,4),
(4,8),(8,4),
(5,8),(8,5),
(5,6),(6,5),
(6,3),(3,6);

-- ============================================================
-- BLOOD GROUP COMPATIBILITY
-- Standard ABO + Rh compatibility rules
-- ============================================================
INSERT INTO compatibility (donor_group, recipient_group) VALUES
-- O- (universal donor)
('O-','A+'),('O-','A-'),('O-','B+'),('O-','B-'),
('O-','AB+'),('O-','AB-'),('O-','O+'),('O-','O-'),
-- O+
('O+','A+'),('O+','B+'),('O+','AB+'),('O+','O+'),
-- A-
('A-','A+'),('A-','A-'),('A-','AB+'),('A-','AB-'),
-- A+
('A+','A+'),('A+','AB+'),
-- B-
('B-','B+'),('B-','B-'),('B-','AB+'),('B-','AB-'),
-- B+
('B+','B+'),('B+','AB+'),
-- AB-
('AB-','AB+'),('AB-','AB-'),
-- AB+ (universal recipient - can only donate to AB+)
('AB+','AB+');

-- ============================================================
-- USERS – all passwords = Password@123
-- hash: $2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS
-- ============================================================
INSERT INTO users (id, name, email, phone, password_hash, role, status) VALUES
-- Admin
(1,  'Admin User',       'admin@lifelink.local',    '9000000001', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'admin',     'active'),
-- Requesters
(2,  'Ravi Kumar',       'ravi@example.com',        '9000000002', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'requester', 'active'),
(3,  'Meena Pillai',     'meena@example.com',       '9000000003', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'requester', 'active'),
(4,  'Suresh Babu',      'suresh@example.com',      '9000000004', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'requester', 'active'),
-- Organizers
(5,  'NGO LifeCare',     'lifecare@example.com',    '9000000005', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'organizer', 'active'),
(6,  'College RedCross', 'redcross@example.com',    '9000000006', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'organizer', 'active'),
-- Donors (40 donors: user_id 7..46)
(7,  'Arjun Sharma',     'arjun@example.com',       '9100000001', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(8,  'Priya Nair',       'priya@example.com',       '9100000002', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(9,  'Karthik Rajan',    'karthik@example.com',     '9100000003', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(10, 'Divya Menon',      'divya@example.com',       '9100000004', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(11, 'Vijay Krishnan',   'vijay@example.com',       '9100000005', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(12, 'Anitha Raj',       'anitha@example.com',      '9100000006', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(13, 'Rahul Verma',      'rahul@example.com',       '9100000007', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(14, 'Kavitha Sundaram',  'kavitha@example.com',    '9100000008', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(15, 'Manoj Patel',      'manoj@example.com',       '9100000009', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(16, 'Sneha Iyer',       'sneha@example.com',       '9100000010', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(17, 'Arun Venkat',      'arun@example.com',        '9100000011', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(18, 'Lakshmi Devi',     'lakshmi@example.com',     '9100000012', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(19, 'Ganesh Murugan',   'ganesh@example.com',      '9100000013', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(20, 'Pooja Reddy',      'pooja@example.com',       '9100000014', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(21, 'Siva Kumar',       'siva@example.com',        '9100000015', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(22, 'Nithya Priya',     'nithya@example.com',      '9100000016', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(23, 'Bala Subramanian', 'bala@example.com',        '9100000017', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(24, 'Revathi Kannan',   'revathi@example.com',     '9100000018', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(25, 'Dinesh Babu',      'dinesh@example.com',      '9100000019', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(26, 'Yamini Srinivas',  'yamini@example.com',      '9100000020', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(27, 'Prakash Mohan',    'prakash@example.com',     '9100000021', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(28, 'Suganya Raj',      'suganya@example.com',     '9100000022', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(29, 'Muthukumar V',     'muthu@example.com',       '9100000023', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(30, 'Chandrika Pillai', 'chandrika@example.com',   '9100000024', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(31, 'Senthil Kumar',    'senthil@example.com',     '9100000025', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(32, 'Padma Venkat',     'padma@example.com',       '9100000026', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(33, 'Krishnan Nair',    'krishnan@example.com',    '9100000027', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(34, 'Mythili Devi',     'mythili@example.com',     '9100000028', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(35, 'Saravanan M',      'saravanan@example.com',   '9100000029', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(36, 'Bharathi Devi',    'bharathi@example.com',    '9100000030', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(37, 'Rajesh Pillai',    'rajesh@example.com',      '9100000031', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(38, 'Uma Maheswari',    'uma@example.com',         '9100000032', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(39, 'Naveen Raj',       'naveen@example.com',      '9100000033', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(40, 'Deepa Krishnan',   'deepa@example.com',       '9100000034', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(41, 'Tamil Selvan',     'tamil@example.com',       '9100000035', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(42, 'Vasantha Kumari',  'vasantha@example.com',    '9100000036', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(43, 'Gokul Raj',        'gokul@example.com',       '9100000037', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(44, 'Malathi Suresh',   'malathi@example.com',     '9100000038', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(45, 'Praveen Kumar',    'praveen@example.com',     '9100000039', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active'),
(46, 'Shanthi Raj',      'shanthi@example.com',     '9100000040', '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS', 'donor', 'active');

-- ============================================================
-- ORGANIZERS
-- ============================================================
INSERT INTO organizers (user_id, org_name, verified) VALUES
(5, 'LifeCare NGO Chennai', 1),
(6, 'KPR College Red Cross Unit', 1);

-- ============================================================
-- DONORS (40 records: varied blood groups, areas, genders, last donation dates)
-- user_ids 7-46 map to donor rows
-- ============================================================
INSERT INTO donors (user_id, blood_group, gender, dob, area_id, last_donation_date, is_available, verified, weight) VALUES
(7,  'A+',  'male',   '1990-03-15', 1, '2026-06-01', 1, 1, 68.0),
(8,  'B+',  'female', '1995-07-22', 1, '2026-05-15', 1, 1, 55.0),
(9,  'O+',  'male',   '1988-11-30', 2, '2026-03-10', 1, 1, 72.5),
(10, 'AB+', 'female', '1993-04-08', 2, NULL,          1, 1, 60.0),
(11, 'A-',  'male',   '1985-09-18', 3, '2026-04-20', 1, 1, 75.0),
(12, 'B-',  'female', '1998-12-05', 3, '2026-07-01', 1, 1, 52.0),
(13, 'O-',  'male',   '1992-06-25', 4, '2025-12-15', 1, 1, 80.0),
(14, 'AB-', 'female', '1987-02-14', 4, '2026-06-10', 0, 1, 58.0),
(15, 'A+',  'male',   '1996-08-30', 5, '2026-08-01', 1, 1, 70.0),
(16, 'B+',  'female', '1991-01-17', 5, '2026-07-15', 1, 1, 57.0),
(17, 'O+',  'male',   '1989-05-22', 6, '2026-02-28', 1, 1, 78.0),
(18, 'AB+', 'female', '1994-10-09', 6, NULL,          1, 1, 62.0),
(19, 'A-',  'male',   '1986-03-14', 1, '2026-01-10', 1, 1, 74.0),
(20, 'B-',  'female', '1997-07-28', 2, '2026-09-01', 0, 1, 54.0),
(21, 'O-',  'male',   '1984-11-05', 3, '2026-05-20', 1, 1, 82.0),
(22, 'AB-', 'female', '1999-02-18', 4, NULL,          1, 1, 56.0),
(23, 'A+',  'male',   '1993-06-11', 5, '2026-07-05', 1, 1, 69.0),
(24, 'B+',  'female', '1988-09-24', 6, '2026-04-15', 1, 1, 63.0),
(25, 'O+',  'male',   '1990-12-07', 1, '2026-03-22', 1, 1, 76.0),
(26, 'AB+', 'female', '1995-04-30', 2, '2026-08-10', 1, 1, 59.0),
(27, 'A-',  'male',   '1987-08-16', 3, '2025-11-30', 1, 1, 73.0),
(28, 'B-',  'female', '1992-01-23', 4, '2026-06-25', 1, 1, 51.0),
(29, 'O-',  'male',   '1983-05-09', 5, '2026-02-14', 1, 1, 85.0),
(30, 'AB-', 'female', '1998-09-17', 6, NULL,          1, 1, 64.0),
(31, 'A+',  'male',   '1991-03-28', 7, '2026-07-20', 1, 1, 71.0),
(32, 'B+',  'female', '1996-07-15', 7, '2026-05-08', 1, 1, 58.0),
(33, 'O+',  'male',   '1985-11-22', 8, '2026-01-18', 1, 1, 79.0),
(34, 'AB+', 'female', '1990-04-06', 8, '2026-09-05', 1, 1, 61.0),
(35, 'A-',  'male',   '1988-08-13', 1, '2026-04-30', 1, 1, 77.0),
(36, 'B-',  'female', '1994-12-20', 2, '2026-06-18', 0, 1, 53.0),
(37, 'O-',  'male',   '1982-06-04', 3, '2025-10-25', 1, 1, 83.0),
(38, 'AB-', 'female', '1997-10-11', 4, NULL,          1, 1, 57.0),
(39, 'A+',  'male',   '1993-02-19', 5, '2026-08-22', 1, 1, 67.0),
(40, 'B+',  'female', '1986-06-27', 6, '2026-03-08', 1, 1, 65.0),
(41, 'O+',  'male',   '1995-10-14', 7, '2026-07-28', 1, 1, 74.0),
(42, 'AB+', 'female', '1989-02-01', 7, '2026-05-30', 1, 1, 60.0),
(43, 'A-',  'male',   '1984-06-18', 8, '2026-01-05', 1, 1, 78.0),
(44, 'B-',  'female', '1991-10-25', 8, '2026-07-12', 1, 1, 55.0),
(45, 'O-',  'male',   '1987-03-31', 1, '2026-04-08', 1, 1, 81.0),
(46, 'AB+', 'female', '1996-08-08', 2, '2026-09-10', 1, 1, 62.0);

-- ============================================================
-- REQUESTS (15 requests in various states)
-- ============================================================
INSERT INTO requests (id, requester_id, patient_name, blood_group, units_needed, units_accepted, hospital, area_id, urgency, contact_phone, needed_by, status, created_at, expires_at, notes) VALUES
(1,  2, 'Ramesh K',      'O-',  2, 0, 'Apollo Hospital',         1, 'Critical',       '9200000001', '2026-10-24 10:00:00', 'Open',        '2026-10-23 08:00:00', '2026-10-25 08:00:00', 'Post surgery emergency'),
(2,  2, 'Sarada Devi',   'A+',  1, 1, 'Fortis Malar',            2, 'Within 24 hours','9200000002', '2026-10-24 18:00:00', 'Donor Found', '2026-10-23 07:30:00', '2026-10-25 07:30:00', NULL),
(3,  3, 'Bhaskar Rao',   'B+',  3, 2, 'MIOT Hospital',           3, 'Critical',       '9200000003', '2026-10-23 20:00:00', 'Open',        '2026-10-23 06:00:00', '2026-10-24 06:00:00', 'Accident victim'),
(4,  3, 'Pavithra S',    'AB+', 1, 0, 'Kauvery Hospital',        4, 'Planned',        '9200000004', '2026-10-30 10:00:00', 'Open',        '2026-10-22 10:00:00', '2026-10-31 10:00:00', 'Elective surgery'),
(5,  4, 'Nagesh Babu',   'O+',  2, 2, 'Vijaya Hospital',         5, 'Critical',       '9200000005', '2026-10-23 15:00:00', 'Donor Found', '2026-10-23 09:00:00', '2026-10-24 09:00:00', NULL),
(6,  2, 'Kamala Devi',   'A-',  1, 1, 'Sri Ramachandra Hospital', 6, 'Within 24 hours','9200000006', '2026-10-24 12:00:00', 'Fulfilled',   '2026-10-20 11:00:00', '2026-10-22 11:00:00', NULL),
(7,  3, 'Venkat Rao',    'B-',  2, 0, 'Apollo Greams',            7, 'Planned',        '9200000007', '2026-11-05 10:00:00', 'Open',        '2026-10-21 08:00:00', '2026-11-06 08:00:00', NULL),
(8,  4, 'Sundaram K',    'AB-', 1, 1, 'Chettinad Hospital',       8, 'Critical',       '9200000008', '2026-10-23 14:00:00', 'Fulfilled',   '2026-10-18 07:00:00', '2026-10-19 07:00:00', 'Rare blood type'),
(9,  2, 'Murali T',      'O-',  1, 0, 'MIOT Hospital',            1, 'Within 24 hours','9200000009', '2026-10-25 10:00:00', 'Open',        '2026-10-23 05:00:00', '2026-10-26 05:00:00', NULL),
(10, 3, 'Saroja Devi',   'A+',  2, 0, 'Fortis Malar',             3, 'Planned',        '9200000010', '2026-11-10 10:00:00', 'Open',        '2026-10-22 09:00:00', '2026-11-11 09:00:00', 'Bypass surgery scheduled'),
(11, 4, 'Nandini R',     'B+',  1, 0, 'Apollo Hospital',          5, 'Critical',       '9200000011', '2026-10-23 18:00:00', 'Open',        '2026-10-23 04:00:00', '2026-10-24 04:00:00', NULL),
(12, 2, 'Karthi M',      'O+',  3, 1, 'Vijaya Hospital',          2, 'Within 24 hours','9200000012', '2026-10-24 20:00:00', 'Open',        '2026-10-23 03:00:00', '2026-10-25 03:00:00', NULL),
(13, 3, 'Anbu Selvan',   'A-',  1, 1, 'SIMS Hospital',            6, 'Critical',       '9200000013', '2026-10-23 12:00:00', 'Fulfilled',   '2026-10-15 08:00:00', '2026-10-16 08:00:00', NULL),
(14, 4, 'Pushpa Rani',   'AB+', 2, 0, 'Kauvery Hospital',         4, 'Planned',        '9200000014', '2026-11-15 10:00:00', 'Open',        '2026-10-23 02:00:00', '2026-11-16 02:00:00', 'Chemotherapy support'),
(15, 2, 'Selvam K',      'B-',  1, 0, 'Chettinad Hospital',       8, 'Within 24 hours','9200000015', '2026-10-24 16:00:00', 'Cancelled',   '2026-10-20 06:00:00', '2026-10-21 06:00:00', 'Patient stable now');

-- ============================================================
-- RESPONSES
-- ============================================================
INSERT INTO responses (request_id, donor_id, status, responded_at) VALUES
(2,  7,  'accepted',  '2026-10-23 08:30:00'),
(5,  9,  'accepted',  '2026-10-23 09:15:00'),
(5,  17, 'accepted',  '2026-10-23 09:20:00'),
(6,  11, 'donated',   '2026-10-21 14:00:00'),
(8,  14, 'donated',   '2026-10-18 12:00:00'),
(13, 19, 'donated',   '2026-10-15 11:00:00'),
(1,  13, 'notified',  NULL),
(1,  21, 'notified',  NULL),
(3,  9,  'accepted',  '2026-10-23 06:30:00'),
(3,  17, 'accepted',  '2026-10-23 06:45:00'),
(12, 26, 'accepted',  '2026-10-23 03:30:00');

-- ============================================================
-- DONATIONS (10 confirmed donations)
-- ============================================================
INSERT INTO donations (id, request_id, donor_id, requester_id, confirmed_at, units) VALUES
(1,  6,  11, 2, '2026-10-21 15:00:00', 1),
(2,  8,  14, 4, '2026-10-18 13:00:00', 1),
(3,  13, 19, 3, '2026-10-15 12:00:00', 1),
(4,  NULL, 13, NULL, '2026-09-10 10:00:00', 1),
(5,  NULL, 21, NULL, '2026-07-22 11:00:00', 1),
(6,  NULL, 29, NULL, '2026-08-15 09:00:00', 1),
(7,  NULL, 37, NULL, '2026-06-05 14:00:00', 1),
(8,  NULL, 45, NULL, '2026-04-08 10:00:00', 1),
(9,  NULL, 27, NULL, '2026-11-30 11:00:00', 1),
(10, NULL, 7,  NULL, '2026-06-01 10:00:00', 1);

-- ============================================================
-- CAMPS (5 donation camps)
-- ============================================================
INSERT INTO camps (id, organizer_id, title, venue, area_id, camp_date, start_time, end_time, contact, description, status) VALUES
(1, 5, 'World Blood Donor Day Camp', 'Community Hall, Anna Nagar', 1, '2026-11-14', '09:00:00', '17:00:00', '9300000001', 'Annual blood donation drive in Anna Nagar. Free health checkup for all donors.', 'approved'),
(2, 6, 'College Blood Drive 2026',   'KPR College Auditorium, Adyar', 2, '2026-11-20', '10:00:00', '16:00:00', '9300000002', 'Student-led blood donation camp. Refreshments provided.', 'approved'),
(3, 5, 'Emergency Blood Reserve',    'Velachery Sports Complex', 4, '2026-12-05', '08:00:00', '14:00:00', '9300000003', 'Building emergency reserves for critical patients.', 'approved'),
(4, 6, 'New Year Blood Camp',        'T. Nagar Kalyana Mandapam', 3, '2026-12-28', '09:00:00', '15:00:00', '9300000004', 'Welcoming new year by saving lives.', 'pending'),
(5, 5, 'Republic Day Blood Drive',   'Tambaram Corporation Ground', 5, '2027-01-26', '08:30:00', '13:30:00', '9300000005', 'Patriotic blood donation camp on Republic Day.', 'approved');

-- ============================================================
-- CAMP INTEREST
-- ============================================================
INSERT INTO camp_interest (camp_id, donor_id) VALUES
(1, 7), (1, 8), (1, 9), (1, 15), (1, 19), (1, 25),
(2, 10), (2, 12), (2, 16), (2, 22),
(3, 13), (3, 21), (3, 29), (3, 33),
(5, 7), (5, 17), (5, 35), (5, 45);

-- Update units_accepted to match responses
UPDATE requests SET units_accepted = 1 WHERE id = 2;
UPDATE requests SET units_accepted = 2 WHERE id = 5;
UPDATE requests SET units_accepted = 1 WHERE id = 6;
UPDATE requests SET units_accepted = 1 WHERE id = 8;
UPDATE requests SET units_accepted = 2 WHERE id = 3;
UPDATE requests SET units_accepted = 1 WHERE id = 12;
UPDATE requests SET units_accepted = 1 WHERE id = 13;
