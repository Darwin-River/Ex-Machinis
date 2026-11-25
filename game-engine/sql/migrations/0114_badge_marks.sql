-- What the badges need that no other table keeps (#1057): each place a drone
-- was seen at (Far Traveller: arrived, mined or traded there; mark
-- 'place:<object_id>') and the last time a drone was found with an empty
-- tank (Never Ran Dry; mark 'ran_dry'). Written only by the website's award
-- pass (App\Services\Badges), one small row a drone and place.
CREATE TABLE IF NOT EXISTS badge_marks (
    kind VARCHAR(8) NOT NULL COMMENT 'drone, company or player',
    subject_id INT UNSIGNED NOT NULL,
    mark VARCHAR(32) NOT NULL,
    game_t BIGINT NOT NULL COMMENT 'When it was last seen',
    PRIMARY KEY (kind, subject_id, mark)
) COMMENT 'Badge progress no other table keeps (#1057)';
