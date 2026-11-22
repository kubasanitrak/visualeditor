-- 002: demo content so the stack can be checked end to end.

INSERT INTO settings (`key`, `value`) VALUES
('site_name', '"Visual Editor Dev"'),
('theme', '{"mode":"auto","vars":{"color-accent":"#2f5bff","radius-media":"4px","anim-duration":"700ms"}}');

INSERT INTO media (id, filename, original_name, mime, kind, width, height, size, alt) VALUES
(1, 'demo/placeholder-1.svg', 'placeholder-1.svg', 'image/svg+xml', 'image', 1200, 900, 0, 'Demo image one'),
(2, 'demo/placeholder-2.svg', 'placeholder-2.svg', 'image/svg+xml', 'image', 1200, 900, 0, 'Demo image two'),
(3, 'demo/placeholder-3.svg', 'placeholder-3.svg', 'image/svg+xml', 'image', 1200, 900, 0, 'Demo image three');

INSERT INTO pages (id, slug, title, meta_description, og_image_id, settings) VALUES
(1, 'home',  'Home',  'Demo portfolio page rendered from the database.', 1, '{}'),
(2, 'about', 'About', 'Second page to test custom slugs.', NULL, '{}'),
(3, '404',   'Not found', '', NULL, '{}');

-- home: hero section
INSERT INTO items (id, page_id, parent_id, sort, type, content, classes, settings) VALUES
(1, 1, NULL, 10, 'section', '{}', '', '{}'),
(2, 1, 1,    10, 'heading', '{"text":"Portfolio","level":1}', 'type-display anim-slide-up', '{}'),
(3, 1, 1,    20, 'text', '{"html":"<p>This page is rendered from the database. No admin layer is needed to show it.</p>"}', 'type-lead type-muted measure anim-fade-in delay-1', '{}');

-- home: three-column gallery
INSERT INTO items (id, page_id, parent_id, sort, type, content, classes, settings) VALUES
(4,  1, NULL, 20, 'section', '{}', 'space-s', '{}'),
(5,  1, 4,    10, 'row', '{}', 'cols-3', '{}'),
(6,  1, 5,    10, 'col', '{}', '', '{}'),
(7,  1, 6,    10, 'image', '{"media_id":1,"caption":"Project one"}', 'fit-cover ratio-3-4 anim-slide-up', '{}'),
(8,  1, 5,    20, 'col', '{}', '', '{}'),
(9,  1, 8,    10, 'image', '{"media_id":2,"caption":"Project two"}', 'fit-cover ratio-3-4 anim-slide-up delay-1', '{}'),
(10, 1, 5,    30, 'col', '{}', '', '{}'),
(11, 1, 10,   10, 'image', '{"media_id":3,"caption":"Project three","focal":"50% 30%"}', 'fit-cover ratio-3-4 anim-slide-up delay-2', '{}');

-- home: dark band with sticky title + text
INSERT INTO items (id, page_id, parent_id, sort, type, content, classes, settings) VALUES
(12, 1, NULL, 30, 'section', '{}', '', '{"bg":"#161616","color":"#f6f5f2"}'),
(13, 1, 12,   10, 'row', '{}', 'cols-2 gap-l', '{}'),
(14, 1, 13,   10, 'col', '{}', '', '{}'),
(15, 1, 14,   10, 'heading', '{"text":"About the work","level":2}', 'pos-sticky', '{}'),
(16, 1, 13,   20, 'col', '{}', '', '{}'),
(17, 1, 16,   10, 'text', '{"html":"<p>Each item carries predefined classes from the style library: layout, fit, type and motion.</p><p>See the <a href=about>about page</a> to test slugs.</p>"}', 'anim-fade-in', '{}');

-- about
INSERT INTO items (id, page_id, parent_id, sort, type, content, classes, settings) VALUES
(18, 2, NULL, 10, 'section', '{}', '', '{}'),
(19, 2, 18,   10, 'heading', '{"text":"About","level":1}', 'type-h1', '{}'),
(20, 2, 18,   20, 'text', '{"html":"<p>Custom slug works. <a href=./>Back home</a>.</p>"}', 'measure', '{}');

-- 404
INSERT INTO items (id, page_id, parent_id, sort, type, content, classes, settings) VALUES
(21, 3, NULL, 10, 'section', '{}', 'text-center', '{}'),
(22, 3, 21,   10, 'heading', '{"text":"Page not found","level":1}', 'type-h2', '{}'),
(23, 3, 21,   20, 'text', '{"html":"<p><a href=./>Go to the homepage</a></p>"}', '', '{}');
