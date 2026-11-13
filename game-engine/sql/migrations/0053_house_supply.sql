-- House supply (#630): what each House exchange sells, how much is on sale,
-- what it refills to, and when its station last restocked and next will,
-- stored once a real minute by the website's tick
-- (App\Services\HouseMarket::storeSupply) for the drones' query 707
-- (house_supply). The website and the API work it out live.
CREATE TABLE IF NOT EXISTS house_supply (
    place INT NOT NULL,
    resource INT NOT NULL,
    price INT NOT NULL COMMENT '0 when none is on sale',
    on_sale INT NOT NULL,
    refill_to INT NOT NULL COMMENT 'What each round tops it back up to: a quarter of the day''s supply',
    daily INT NOT NULL,
    sold INT NOT NULL COMMENT 'Units sold today',
    last_restock_t BIGINT NULL COMMENT 'Game time it last imported this good (null: not in two game days)',
    round_seconds BIGINT NULL COMMENT 'Game seconds between its rounds of restocking (median)',
    next_round_t BIGINT NULL COMMENT 'Game time its next round is due',
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (resource, place)
);
