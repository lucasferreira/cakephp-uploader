<?php
/**
 * Uploader Behavior
 *
 * Attach files/Upload files in based model forms
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * Basic Usage:
 *
 * var $actsAs => array('Uploader' => array(
 * 	'files' => array(
 * 		'img' => array('src' => 'youpathrelativetowebroot/image_:id.jpg')
 * 	)
 * ));
 *
 * @author Lucas Ferreira
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @copyright Copyright 2011-2014, Burn web.studio - http://www.burnweb.com.br/
 * @version 1.3b
 */

define('UPLOADER_ONE_MB', 1048576);

class UploaderBehavior extends ModelBehavior
{
	public $options = array();
	public $global = array();
	public $dataFiles = array();

	public function setup(&$model, $settings=array())
	{
		$this->global = array(
			'delete' => true,
			'rootPath' => WWW_ROOT,
			'maxSize' => UPLOADER_ONE_MB * 2,
			'extensions' =>  array(
				"jpg", "jpeg",
				"png",
				"gif",
				"txt",
				"htm", "html",
				"xml",
				"pdf",
				"xls", "xlsx",
				"doc", "docx",
				"dat",
				"mp4", "m4v",
				"mov",
				"wmv",
				"flv",
				"mp3", "wav",
				"swf"
			)
		);

	    $_options = array_merge(array(
			'name' => $model->alias,
			'schema' => $model->schema(),
			'delete' => $this->global['delete']
		), $settings);

		if(!empty($_options['files']))
		{
			foreach($_options['files'] as $field=>$config)
			{
				$_options['files'][$field] = array_merge($this->global, $_options['files'][$field]);
			}
		}
		$this->options[$model->alias] = &$_options;
	}

	public function fileform2data(&$model, $ff, $data=array())
	{
		$new_data = array();
		foreach($ff as $key=>$form)
		{
			if(!is_array($form) || empty($form['tmp_name'])) continue;

			$fdata = array();
			$fkeys = array_keys($form);
			for($i=0; $i<count($form['tmp_name']); $i++)
			{
				foreach($fkeys as $fk)
				{
					$fdata[$fk] = $form[$fk][$i];
				}
				$new_data[] = array_merge($data, array("$key" => $fdata, "i" => $i));
			}
		}
		return $new_data;
	}

	public function deleteFile(&$model, $field, $id)
	{
		if(!empty($this->options[$model->alias]['files'][$field]))
		{
			$config = $this->options[$model->alias]['files'][$field];

			$fdata = $model->find('first', array(
				'conditions' => array("{$model->alias}.{$model->primaryKey}" => $id),
				'recursive' => false
			));

			if(!empty($fdata) && !empty($fdata[$model->alias][$field]))
			{
				$fd = $config['rootPath'] . $fdata[$model->alias][$field];
				if(file_exists($fd))
				{
					@unlink($fd);
					return true;
				}
			}
		}
		return false;
	}

	public function beforeSave(&$model, $a)
	{
		$this->dataFiles = array();

		if(!empty($model->data[$model->alias]) && !empty($this->options[$model->alias]['files']))
		{
			$data = $model->data[$model->alias];
			foreach($this->options[$model->alias]['files'] as $field=>$config)
			{
				if(!empty($data[$field]) && !empty($data[$field]['tmp_name']) && is_uploaded_file($data[$field]['tmp_name']))
				{
					$this->dataFiles[$model->alias][$field] = $data[$field];
					unset($model->data[$model->alias][$field]);
				}
			}
		}
	}

	public function afterSave(&$model, $created)
	{
		if(!empty($this->dataFiles[$model->alias]))
		{
			$id = !empty($model->data[$model->alias][$model->primaryKey]) ? $model->data[$model->alias][$model->primaryKey] : $model->{$model->primaryKey};
			$data = array_merge($model->data[$model->alias], $this->dataFiles[$model->alias], array("{$model->primaryKey}" => $id));
			foreach($this->options[$model->alias]['files'] as $field=>$config)
			{
				if(!empty($data[$field]) && !empty($data[$field]['tmp_name']) && is_uploaded_file($data[$field]['tmp_name']))
				{
					$file_name_now = $this->__getFileName($config, $data);
					if($created !== true && file_exists($config['rootPath'] . $file_name_now))
					{
						@unlink($config['rootPath'] . $file_name_now);
					}

					$file_name = $this->__getFullFileName($field, $config, $id, $data);
					if(move_uploaded_file($data[$field]['tmp_name'], $file_name))
					{
						@chmod($file_name, 0755);

						if(!empty($config['type']) && $config['type'] == "image")
						{
							$this->__resizeImage($file_name, $config);
						}

						if(isset($this->options[$model->alias]['schema'][$field]))
						{
							$model->{$model->primaryKey} = $id;
							$model->saveField('img', $this->__getFileName($config, $data));
						}
					}
				}
			}
		}
	}

	public function afterFind(&$model, $data=array())
	{
		foreach($data as $i=>$d)
		{
			foreach($this->options as $_modeName => $cfg)
			{
				if(!empty($d[$_modeName]))
				{
					$ad = $d[$_modeName];
					if(empty($ad[0]))
					{
						foreach($cfg['files'] as $field=>$config)
						{
							if(empty($ad[$field])) $ad[$field] = $this->__getFileName($config, $ad);
						}
					}
					else
					{
						foreach($ad as $k=>$dd)
						{
							foreach($cfg['files'] as $field=>$config)
							{
								if(empty($ad[$k][$field])) $ad[$k][$field] = $this->__getFileName($config, $dd);
							}
						}
					}

					$d[$_modeName] = $ad;
				}
			}

			$data[$i] = $d;
		}

		return $data;
	}

	public function beforeDelete($model)
	{
		if(!empty($model->{$model->primaryKey}) && $this->options[$model->alias]['delete'])
		{
			foreach($this->options[$model->alias]['files'] as $field=>$config)
			{
				if(!isset($config['delete']) || $config['delete'] === true)
				{
					$this->deleteFile($model, $field, $model->{$model->primaryKey});
				}
			}
		}

		return true;
	}

	/**
	* Validation rules...
	*/
	public function isFile(&$model, $data, $check)
	{
		$field_name = key($data);
		if(!isset($this->options[$model->alias]['files'][$field_name]))
		{
			return true;
		}
		else
		{
			$field = $this->options[$model->alias]['files'][$field_name];
		}

		if(func_num_args() == 3)
		{
			$ae = $field['extensions'];
		}
		else
		{
			$ae = is_array($check) ? $check : explode(",", $check);
			$ae = array_map("trim", $ae);
		}

 		if((empty($data[$field_name]['tmp_name']) || !is_uploaded_file($data[$field_name]['tmp_name'])))
		{
			return false;
		}

		if(!empty($data[$field_name]) && is_uploaded_file($data[$field_name]['tmp_name']))
		{
			return ( array_search( end( explode(".", $data[$field_name]['name']) ), $ae) !== false );
		}

		return true;
	}

	public function isFileUpload(&$model, $check, $rule=array())
	{
		if(empty($check)) return true;

		list($field_name, $data) = each($check);

		if(empty($data) || empty($data['tmp_name']) || !is_uploaded_file($data['tmp_name']))
		{
			return false;
		}

		return true;
	}

	public function isNotBigger(&$model, $check, $rule=array())
	{
		if(empty($check)) return true;

		list($field_name, $data) = each($check);

		if(empty($data) || empty($data['tmp_name']) || !is_uploaded_file($data['tmp_name'])) return true;

		if(!empty($this->options[$model->alias]['files'][$field_name]))
		{
			$field = $this->options[$model->alias]['files'][$field_name];
			$maxSize = !empty($rule['maxSize']) ? $rule['maxSize'] : $field['maxSize'];

			if($data['size'] > $maxSize || $data['error'] == UPLOAD_ERR_INI_SIZE)
			{
				return false;
			}
		}

		return true;
	}

	public function isValidExtension(&$model, $check, $rule=array())
	{
		if(empty($check)) return true;

		list($field_name, $data) = each($check);

		if(empty($data) || empty($data['tmp_name']) || !is_uploaded_file($data['tmp_name'])) return true;

		if(!empty($this->options[$model->alias]['files'][$field_name]))
		{
			$field = $this->options[$model->alias]['files'][$field_name];
			$extensions = !empty($rule['extensions']) ? $rule['extensions'] : $field['extensions'];

			return ( array_search( end( explode(".", $data['name']) ), $extensions) !== false );
		}

		return true;
	}

	/**
	* Retrieve file name to inject in find results...
	*/
	private function __getFileName($config, $data)
	{
		$__path = $config['rootPath'];
		$__ae = $config['extensions'];

		foreach($data as $i=>$d)
		{
			if(is_array($d)) unset($data[$i]);
		}

		if(strpos($config['src'], ":ext") === false)
		{
			$__name = String::insert($config['src'], $data);
			if(file_exists($__path . $__name))
			{
				return $__name;
			}
		}
		else
		{
			foreach($__ae as $ext)
			{
				$data = array_merge($data, array("ext" => $ext));
				$__name = String::insert($config['src'], $data);
				if(file_exists($__path . $__name))
				{
					return $__name;
				}
			}
		}

		return null;
	}

	/**
	* Get full file name to save new files...
	*/
	private function __getFullFileName($field, $config, $id, $data=array())
	{
		$__path = $config['rootPath'];
		$__ext = end(explode(".", strtolower($data[$field]['name'])));

		$data = array_merge($data, array('id' => $id, 'ext' => $__ext));
		foreach($data as $i=>$d)
		{
			if(is_array($d)) unset($data[$i]);
		}

		$__name = String::insert($config['src'], $data);

		return $__path . $__name;
	}

	/**
	* Helper to resize images... only simple ops!
	*/
	private function __resizeImage($photo, $config=array())
	{
		$img_size = getImageSize($photo);

		if(!empty($config['w'])) $config['width'] = $config['w'];
		if(!empty($config['h'])) $config['height'] = $config['h'];
		if(!empty($config['q'])) $config['quality'] = $config['q'];

		if(empty($config['width']) && empty($config['height']))
		{
			$config['width'] = $img_size[0];
			$config['height'] = $img_size[1];
		}

		if(empty($config['height']))
		{
			$config['height'] = round($img_size[1] * ($config['width']/$img_size[0]));
		}

		if(empty($config['width']))
		{
			$config['width'] = round($img_size[0] * ($config['height']/$img_size[1]));
		}

		if(empty($config['quality']))
		{
			$config['quality'] = 95;
		}

		$resizeMethod = class_exists("imagick") ? "__resizeImageImagick" : "__resizeImageGD";

		return $this->{$resizeMethod}($photo, $config);
	}

	/**
	* Helper to resize images using GD extension...
	*/
	private function __resizeImageGD($photo, $config=array())
	{
		$str_image = @file_get_contents($photo) or die("Image not found");
		$original_image = imagecreatefromstring($str_image);
		$final_image = imagecreatetruecolor($config['width'], $config['height']);

		if(strpos($photo, ".gif") !== false)
		{
			$transpIndex = imagecolortransparent($original_image);
			$transpColor = array('red' => 255, 'green' => 255, 'blue' => 255);

			if($transpIndex >= 0)
			{
				$transpColor = imagecolorsforindex($original_image, $transpIndex);
			}

			$transpIndex = imagecolorallocate($final_image, $transpColor['red'], $transpColor['green'], $transpColor['blue']);
			imagefill($final_image, 0, 0, $transpIndex);
			imagecolortransparent($final_image, $transpIndex);

			imagecopyresampled($final_image, $original_image, 0, 0, 0, 0, $config['width'], $config['height'], imagesx($original_image), imagesy($original_image));

			imagegif($final_image, $photo);
		}
		else if(strpos($photo, ".png") !== false)
		{
			imagealphablending($final_image, false);
			imagesavealpha($final_image, true);

			imagealphablending($original_image, false);
			imagesavealpha($original_image, true);

			imagecopyresampled($final_image, $original_image, 0, 0, 0, 0, $config['width'], $config['height'], imagesx($original_image), imagesy($original_image));

			imagepng($final_image, $photo);
		}
		else
		{
			imagefill($final_image, 0, 0, imagecolorallocate($final_image, 255, 255, 255));

			imagecopyresampled($final_image, $original_image, 0, 0, 0, 0, $config['width'], $config['height'], imagesx($original_image), imagesy($original_image));

			imagejpeg($final_image, $photo, $config['quality']);
		}

		imagedestroy($original_image);
		imagedestroy($final_image);

		return $photo;
	}

	/**
	* Helper to resize images using ImageMagick extension...
	*/
	private function __resizeImageImagick($photo, $config=array())
	{
		if(!file_exists($photo))
		{
			return false;
		}

		$imagickVersion = phpversion('imagick');

		$image = new imagick();
		$image->readImage($photo);
		$image->thumbnailImage($config['width'], $config['height'], !($imagickVersion[0] == 3));
		$image->setImageCompressionQuality($config['quality']);

		if(strpos($photo, ".gif") !== false)
		{
			$image->setImageFormat("gif");
		}
		else if(strpos($photo, ".png") !== false)
		{
			$image->setImageFormat("png");
		}
		else
		{
			$image->setImageFormat("jpg");
		}

		if(!$image->writeImage($photo))
		{
			return false;
		}

		$image->clear();
		$image->destroy();

		return $photo;
	}
}
?>
