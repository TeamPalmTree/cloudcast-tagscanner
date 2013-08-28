<?php

class TagScanner {

    public static function scan_files($directory)
    {
        //////////////////
        // SETUP GETID3 //
        //////////////////

        // get instance
        $getID3 = new getID3;

        ////////////////////
        // SCAN DIRECTORY //
        ////////////////////

        // keep track of scanned files
        $scanned_files = array();
        // open directory
        $directory_handle = opendir($directory);
        // get each file
        while (($file_title = readdir($directory_handle)) !== false)
        {

            /////////////////////////
            // GET FILE TITLE/NAME //
            /////////////////////////

            // get file name
            $file_name = $directory . $file_title;
            // set system file title/name
            $system_file_title = $file_title;
            $system_file_name = $file_name;
            // fix system file name
            if (GETID3_OS_ISWINDOWS)
                $file_name = utf8_encode($file_name);

            ///////////////////
            // VALIDATE FILE //
            ///////////////////

            // see if this is a valid file
            if (substr($system_file_title, 0, 1) == '.')
                continue;
            // verify file
            if (!is_file($system_file_name))
                continue;

            ////////////////////
            // GET & ADD FILE //
            ////////////////////

            try
            {
                // get file info
                $file_info = $getID3->analyze($system_file_name);
                // move all tags to comments
                getid3_lib::CopyTagsToComments($file_info);
                // add to scanned files
                $scanned_files[$file_name] = self::get_file($file_info);
            }
            catch(Exception $e)
            {
                continue;
            }

        }

        // close directory
        closedir($directory_handle);
        // success
        return $scanned_files;
    }

    private static function get_file($file_info)
    {

        // create file array
        $file = array();
        // get file comments
        $file_info_comments = $file_info['comments'];
        $file_info_audio = $file_info['audio'];

        // set date
        $file['date'] = self::get_date($file_info_comments);
        // set track
        $file['track'] = self::get_first_value('track', $file_info_comments);
        // set BPM
        $file['BPM'] = self::get_first_value('bpm', $file_info_comments);
        // set bit rate
        $file['bit_rate'] = self::get_value('bitrate', $file_info_audio, true);
        // set bit rate
        $file['sample_rate'] = self::get_value('sample_rate', $file_info_audio, true);
        // set duration
        $file['duration'] = self::get_duration($file_info);
        // set title
        $file['title'] = self::get_first_value('title', $file_info_comments, true);
        // set album
        $file['album'] = self::get_first_value('album', $file_info_comments);
        // set artist
        $file['artist'] = self::get_first_value('artist', $file_info_comments, true);
        // set composer
        $file['composer'] = self::get_first_value('composer', $file_info_comments);
        // set conductor
        $file['conductor'] = self::get_first_value('conductor', $file_info_comments);
        // set copyright
        $file['copyright'] = self::get_first_value('copyright', $file_info_comments);
        // set genre
        $file['genre'] = self::get_first_value('genre', $file_info_comments, true);
        // set ISRC
        $file['ISRC'] = self::get_first_value('isrc', $file_info_comments);
        // set label
        $file['label'] = self::get_first_value('label', $file_info_comments);
        // set language
        $file['language'] = self::get_first_value('language', $file_info_comments);
        // set mood
        $file['mood'] = self::get_first_value('mood', $file_info_comments);
        // set musical key
        $file['key'] = self::get_first_value('initial_key', $file_info_comments);
        // set energy
        $file['energy'] = self::get_first_value('comment', $file_info_comments);
        // set rating
        $file['rating'] = self::get_rating($file_info);

        // success
        return $file;

    }

    private static function get_value($name, $array, $required = false)
    {

        if (isset($array[$name]))
            return $array[$name];
        if (!$required)
            return null;
        throw new Exception($name . ' is a required');

    }

    private static function get_first_value($name, $array, $required = false)
    {

        if (isset($array[$name][0]))
            return $array[$name][0];
        if (!$required)
            return null;
        throw new Exception($name . ' is a required');

    }

    private static function get_duration($file_info)
    {
        // get un-normalized duration
        $duration = self::get_value('playtime_string', $file_info, true);
        // return normalized duration
        return Helper::normalized_duration($duration);
    }

    private static function get_rating($file_info)
    {
        // get id3v2
        $file_info_id3v2 = self::get_value('id3v2', $file_info);
        if (!$file_info_id3v2)
            return null;

        // get POPM
        $file_info_id3v2_POPM = self::get_value('POPM', $file_info_id3v2);
        if (!$file_info_id3v2_POPM)
            return null;

        // get rating
        $rating = $file_info_id3v2_POPM[0]['rating'];

        /* 224-255 = 5
           160-223 = 4
           096-159 = 3
           032-095 = 2
           001-031 = 1 */
        if (($rating >= 224) && ($rating <= 255))
            return 5;
        if (($rating >= 160) && ($rating <= 223))
            return 4;
        if (($rating >= 96) && ($rating <= 159))
            return 3;
        if (($rating >= 32) && ($rating <= 95))
            return 2;
        if (($rating >= 1) && ($rating <= 31))
            return 1;
        return null;
    }

    private static function get_date($file_info_comments)
    {
        // get year
        $year = self::get_first_value('year', $file_info_comments, true);
        // get the date
        $date = self::get_first_value('date', $file_info_comments);

        // if we have no date, return the first of the year
        if (!$date)
        {
            // day and month are 1/1
            $day = 1;
            $month = 1;
        }
        else
        {
            // split the date into two chunks
            $day = substr($date, 0, 2);
            $month = substr($date, 2, 2);
        }

        // create datetime for this string
        $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $year . '-' . $month . '-' . $day . ' 00:00:00');
        // return the date string
        return Helper::server_datetime_string($datetime);
    }

}