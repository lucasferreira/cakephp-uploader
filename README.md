# Uploader Behavior for CakePHP 2.x

Easy way to upload your files in Cake model based forms

## Usage:

Load the Uploader behavior in your model:

	var $actsAs => array('Uploader' => array(
		'files' => array(
			'img' => array('src' => 'youpathrelativetowebroot/image_:id.jpg')
		)
	));

When you find some data, the new img/file virtual field will be in our results:

	$entry = $this->YourModel->find('all');

	pr($entry);


@lucasferreira
