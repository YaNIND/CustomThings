<?php

namespace romainsav\customthings\items\json;

class ToolJson extends BaseJson {

    /**
     * @var int
     * @required
     */
    public int $max_durability;

    /**
     * @var int
     * @required
     */
    public int $damage;
}