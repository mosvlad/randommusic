<?php

$dir = './1000';
$file_arr = array();
if ($dh = opendir($dir))
{
	while (($file = readdir($dh)) !== false)
	{
		if (!is_dir($dir.'/'.$file))
		{
			if (pathinfo($file)['extension'] == 'mp3')
			{
				$file_arr[] = '/1000/'.$file;
			}
		}

	}
closedir($dh);
}

$dir = './music';
if ($dh = opendir($dir))
{
	while (($file = readdir($dh)) !== false)
	{
		if (!is_dir($dir.'/'.$file))
		{
			if (pathinfo($file)['extension'] == 'mp3')
			{
				$file_arr[] = '/music/'.$file;
			}
		}

	}
closedir($dh);
}

$dir = './upload';
if ($dh = opendir($dir))
{
	while (($file = readdir($dh)) !== false)
	{
		if (!is_dir($dir.'/'.$file))
		{
			if (pathinfo($file)['extension'] == 'mp3')
			{
				$file_arr[] = '/upload/'.$file;
			}
		}

	}
closedir($dh);
}

$random_file = $file_arr[array_rand($file_arr)];
echo strval($random_file);
?>