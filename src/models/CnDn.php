<?php

namespace Abs\CnDnPkg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CnDn extends Model {
	use SoftDeletes;
	protected $table = 'cn_dns';
	protected $fillable = [
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];
}
