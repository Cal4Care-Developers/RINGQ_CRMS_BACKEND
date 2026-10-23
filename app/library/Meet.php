<?php

use Phalcon\Di\Injectable;
use Phalcon\Db\Column;

class Meet extends Injectable
{
    public function ringqmeet($data = []) {
		$security = new \Phalcon\Security\Random();
        $roomId = $security->uuid();
		$owner = "{$data['first_name']}{$data['last_name']}{$data['extension_no']}";

		// This UUID is an external reference only. The authenticated v1 control
		// plane lazily maps it to a tenant-scoped SFU meeting on first host use;
		// never add new records to the unscoped legacy `rooms` list.
		return [
			'owner' => $owner,
			'room_name' => $roomId
		];
	}
}
