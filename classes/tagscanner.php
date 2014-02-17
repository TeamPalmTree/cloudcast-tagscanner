<?php

class TagScanner {

    public static function write_file($file)
    {

        //////////////////
        // SETUP GETID3 //
        //////////////////

        // get instance
        $getID3 = new getID3;
        // set up encoding
        $getID3->setOption(array('encoding' => 'UTF-8'));
        // set up writer
        $getid3_writetags = new getid3_writetags;
        // set up writer properties
        $getid3_writetags->filename = $file->name;
        $getid3_writetags->tagformats = array('id3v1', 'id3v2.3');
        $getid3_writetags->overwrite_tags = true;
        $getid3_writetags->tag_encoding = 'UTF-8';
        $getid3_writetags->remove_other_tags = true;

        ///////////////////////
        // POPULATE TAG DATA //
        ///////////////////////

        $tag_data = array();
        // set date
        if (isset($file->date))
        {
            // get the file date
            $file_date = Helper::datetime_string_date($file->date);
            // split up date
            $file_date_parts = explode('-', $file_date);
            // set date tag data
            $tag_data['year'] = array($file_date_parts[0]);
            $tag_data['date'] = array($file_date_parts[2] . $file_date_parts[1]);
        }
        // set BPM
        if (isset($file->BPM))
        {
            $tag_data['bpm'] = array($file->BPM);
            $tag_data['beats_per_minute'] = array($file->BPM);
        }
        // set title
        if (isset($file->title))
            $tag_data['title'] = array($file->title);
        // set album
        if (isset($file->album))
            $tag_data['album'] = array($file->album);
        // set artist
        if (isset($file->artist))
            $tag_data['artist'] = array($file->artist);
        // set composer
        if (isset($file->composer))
            $tag_data['composer'] = array($file->composer);
        // set conductor
        if (isset($file->conductor))
            $tag_data['conductor'] = array($file->conductor);
        // set copyright
        if (isset($file->copyright))
            $tag_data['copyright'] = array($file->copyright);
        // set genre
        if (isset($file->genre))
            $tag_data['genre'] = array($file->genre);
        // set ISRC
        if (isset($file->ISRC))
            $tag_data['isrc'] = array($file->ISRC);
        // set language
        if (isset($file->language))
            $tag_data['language'] = array($file->language);
        // set musical key
        if (isset($file->key))
            $tag_data['initial_key'] = array($file->key);
        // set energy
        if (isset($file->energy))
            $tag_data['comment'] = array($file->energy);
        
        ///////////////////
        // SAVE TAG DATA //
        ///////////////////

        // set tag data
        $getid3_writetags->tag_data = $tag_data;
        // write tag data
        $getid3_writetags->WriteTags();

        /////////////////
        // RENAME FILE //
        /////////////////

        // get path information about the file name
        $file_path_info = pathinfo($file->name);
        // get file title
        $new_file_title = Helper::sanitize_file_title($file->artist . ' - ' . $file->title);
        // reassemble the file name
        $new_file_name = $file_path_info['dirname'] . DIRECTORY_SEPARATOR . $new_file_title . '.' . $file_path_info['extension'];
        // see if we need to rename file
        if ($file->name != $new_file_name)
        {
            // rename the file
            rename($file->name, $new_file_name);
            // update file name
            $file->name = $new_file_name;
        }

        ///////////////////////
        // SET MODIFIED TIME //
        ///////////////////////

        // update modified time
        $file->modified_on = filemtime($file->name);

    }

    public static function scan_directory($directory, &$modified_ons)
    {

        // get instance
        $getID3 = new getID3;
        // create array for scanned file storage
        $scanned_files = array();
        // scan files and directories recursively
        self::recursively_scan_directory($directory, $scanned_files, $modified_ons, $getID3);
        // success
        return $scanned_files;

    }

    public static function recursively_scan_directory($directory, &$scanned_files, &$modified_ons, $getID3)
    {

        ////////////////////
        // SCAN DIRECTORY //
        ////////////////////

        // open directory
        $directory_handle = opendir($directory);
        // get each file
        while (($file_title = readdir($directory_handle)) !== false)
        {

            /////////////////////
            // VERIFY NOT DOTS //
            /////////////////////

            // make sure not . or ..
            if (substr($file_title, 0, 1) == '.')
                continue;

            ////////////////////////////
            // GET & VERIFY FILE NAME //
            ////////////////////////////

            // get file name
            $file_name = $directory . $file_title;
            // verify name falls in ascii range
            if (preg_match('/[^\x20-\x7f]/', $file_name))
            {
                // log this non-ascii file name
                Log::warning('TagScanner: Non-ASCII File Name Ignored: ' . $file_name);
                continue;
            }

            /////////////////////////
            // RECURSE DIRECTORIES //
            /////////////////////////

            // if this "file" is a dir, recurse
            if (is_dir($file_name))
            {
                // get next directory to scan
                $next_directory = $file_name . DIRECTORY_SEPARATOR;
                // run scan
                self::recursively_scan_directory($next_directory, $scanned_files, $modified_ons, $getID3);
                // keep calm on move on
                continue;
            }

            ///////////////////
            // PROCESS FILES //
            ///////////////////

            // verify file is a file
            if (!is_file($file_name))
                continue;

            /////////////////////////////////
            // SEE IF IT HAS BEEN MODIFIED //
            /////////////////////////////////

            // get modified on
            $file_modified_on = filemtime($file_name);
            // get the modified time from the modified ons array
            $previous_file_modified_on = isset($file_modified_ons[$file_name]) ? $file_modified_ons[$file_name] : null;
            // if they are the same, add to array and continue
            if (($file_modified_on - $previous_file_modified_on) < 60)
            {
                $scanned_files[$file_name] = null;
                continue;
            }

            //////////////////
            // ANALYZE FILE //
            //////////////////

            try
            {

                // get file info
                $file_info = $getID3->analyze($file_name);
                // flatten file into
                $file_info = self::flatten_file_info($file_info);
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

    }

    protected static function flatten_file_info($file_info)
    {

        // set up array
        $flat_file_info = array();

        ////////////////
        // ID3V2 TAGS //
        ////////////////

        // if we have v2 data, process
        if (isset($file_info['id3v2']))
        {
            // loop over id3v2 vars
            foreach ($file_info['id3v2'] as $file_info_id3v2_name => &$file_info_id3v2_value)
            {

                // add flat values to array
                if (!is_array($file_info_id3v2_value))
                {
                    // verify not empty
                    if (self::scrub_value($file_info_id3v2_value))
                        $flat_file_info[$file_info_id3v2_name] = $file_info_id3v2_value;
                    continue;
                }

                // now only look for T sections
                if (strpos($file_info_id3v2_name, 'T') !== 0)
                    continue;

                // check for custom frames
                if ($file_info_id3v2_name == 'TXXX')
                {
                    // loop over custom tags, add to flat list
                    foreach ($file_info_id3v2_value as &$file_info_id3v2_custom_frame)
                    {
                        // get data
                        $file_info_id3v2_custom_frame_data = mb_convert_encoding($file_info_id3v2_custom_frame['data'], 'UTF-8', $file_info_id3v2_custom_frame['encoding']);
                        // verify not empty
                        if (self::scrub_value($file_info_id3v2_custom_frame_data))
                            $flat_file_info[strtolower($file_info_id3v2_custom_frame['description'])] = $file_info_id3v2_custom_frame_data;

                    }
                    continue;
                }

                // check for popularmeter
                if ($file_info_id3v2_name == 'POPM')
                {
                    // get rating
                    $file_rating = $file_info_id3v2_value[0]['rating'];
                    // set rating if not empty
                    if (self::scrub_value($file_rating))
                        $flat_file_info['rating'] = $file_rating;
                    continue;
                }

                // get first tag frame
                $first_file_info_id3v2_frame = $file_info_id3v2_value[0];
                // get frame data
                $first_file_info_id3v2_frame_data = mb_convert_encoding($first_file_info_id3v2_frame['data'], 'UTF-8', $first_file_info_id3v2_frame['encoding']);
                // add standard v2 tag data if not empty
                if (self::scrub_value($first_file_info_id3v2_frame_data))
                    $flat_file_info[$first_file_info_id3v2_frame['framenameshort']] = $first_file_info_id3v2_frame_data;
            }

        }

        ////////////////
        // ID3V1 FLAT //
        ////////////////

        // if we have v1 data, process
        if (isset($file_info['tags']['id3v1']))
        {
            // loop over id3v1 vars
            foreach ($file_info['tags']['id3v1'] as $file_info_id3v1_name => &$file_info_id3v1_value)
            {
                $first_file_info_id3v1_value = $file_info_id3v1_value[0];
                if (self::scrub_value($first_file_info_id3v1_value))
                    $flat_file_info[$file_info_id3v1_name] = $first_file_info_id3v1_value;
            }
        }

        ////////////////
        // ID3V2 FLAT //
        ////////////////

        // if we have v1 data, process
        if (isset($file_info['tags']['id3v2']))
        {
            // loop over id3v1 vars
            foreach ($file_info['tags']['id3v2'] as $file_info_id3v2_name => &$file_info_id3v2_value)
            {
                $first_file_info_id3v2_value = $file_info_id3v2_value[0];
                if (self::scrub_value($first_file_info_id3v2_value))
                    $flat_file_info[$file_info_id3v2_name] = $first_file_info_id3v2_value;
            }
        }

        ////////////////
        // AUDIO FLAT //
        ////////////////

        // loop over audio data
        foreach ($file_info['audio'] as $file_info_audio_name => &$file_info_audio_value)
        {
            // skip arrays
            if (is_array($file_info_audio_value))
                continue;
            // add to flat array
            if (self::scrub_value($file_info_audio_value))
                $flat_file_info[$file_info_audio_name] = $file_info_audio_value;
        }

        //////////
        // FILE //
        //////////

        // loop over audio data
        foreach ($file_info as $file_info_name => &$file_info_value)
        {
            // skip arrays
            if (is_array($file_info_value))
                continue;
            // add to flat array
            if (self::scrub_value($file_info_value))
                $flat_file_info[$file_info_name] = $file_info_value;
        }

        // success
        return $flat_file_info;

    }

    protected static function scrub_value(&$value)
    {
        if (empty($value))
            return false;
        $value = preg_replace('/[^(\x20-\x7F)]*/', '', $value);
        return true;
    }

    protected static function get_file($file_info)
    {

        // create file array
        $file = array();

        /////////////////////
        // REQUIRED FIELDS //
        /////////////////////

        // set date
        $file['date'] = self::get_date($file_info);
        // set bit rate
        $file['bit_rate'] = $file_info['bitrate'];
        // set bit rate
        $file['sample_rate'] = $file_info['sample_rate'];
        // set duration
        $file['duration'] = self::get_duration($file_info);
        // set artist
        $file['artist'] = $file_info['artist'];
        // set title
        $file['title'] = $file_info['title'];
        // set genre
        $file['genre'] = $file_info['genre'];

        ////////////////////////////
        // OPTIONAL FIELDS (EASY) //
        ////////////////////////////

        // set album
        $file['album'] = isset($file_info['album']) ? $file_info['album'] : null;
        // set composer
        $file['composer'] = isset($file_info['composer']) ? $file_info['album'] : null;
        // set conductor
        $file['conductor'] = isset($file_info['conductor']) ? $file_info['conductor'] : null;
        // set copyright
        $file['copyright'] = isset($file_info['copyright']) ? $file_info['copyright'] : null;
        // set ISRC
        $file['ISRC'] = isset($file_info['isrc']) ? $file_info['isrc'] : null;
        // set language
        $file['language'] = isset($file_info['language']) ? $file_info['language'] : null;

        ////////////////////////////
        // OPTIONAL FIELDS (HARD) //
        ////////////////////////////

        // set BPM
        $file['BPM'] = self::get_bpm($file_info);
        // set musical key
        $file['key'] = self::get_key($file_info);
        // set energy
        $file['energy'] = self::get_energy($file_info);
        // set rating
        $file['rating'] = self::get_rating($file_info);

        // success
        return $file;

    }

    protected static function get_date(&$file_info)
    {

        // verify we have a year, else return now
        if (!isset($file_info['year']) || (empty($file_info['year'])))
            return Helper::server_datetime_string();

        // get year
        $year = $file_info['year'];
        // explode by dash
        $year_parts = explode('-', $year);
        // get year parts count
        $year_parts_count = count($year_parts);
        // if it has three dashes, it is YYYY-MM-DD format,
        // else if 2, it is YYYY-MM format,
        // else if 1, it is just a year
        if ($year_parts_count == 3)
        {
            $year = $year_parts[0];
            $month = $year_parts[1];
            $day = $year_parts[2];
        }
        else if ($year_parts_count == 2)
        {
            $year = $year_parts[0];
            $month = $year_parts[1];
            $day = 1;
        }
        else if ($year_parts_count == 1)
        {
            // if we have no date, return the first of the year
            if (!isset($file_info['date']))
            {
                // day and month are 1/1
                $month = 1;
                $day = 1;
            }
            else
            {
                $date = $file_info['date'];
                // split the date into two chunks
                $month = substr($date, 2, 2);
                $day = substr($date, 0, 2);
            }
        }

        // create datetime for this string
        $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $year . '-' . $month . '-' . $day . ' 00:00:00');
        // return the date string
        return Helper::server_datetime_string($datetime);

    }

    protected static function get_duration($file_info)
    {
        // get un-normalized duration
        $duration = $file_info['playtime_string'];
        // return normalized duration
        return Helper::normalize_duration($duration);
    }

    protected static function get_BPM($file_info)
    {

        // check for custom tag
        if (isset($file_info['bpm (beats per minute)']))
            return $file_info['bpm (beats per minute)'];
        // check the standard BPM field
        if (isset($file_info['bpm']))
            return $file_info['bpm'];
        // fail
        return null;

    }

    protected static function get_key($file_info)
    {

        // get the custom initial key value
        if (isset($file_info['initial key']))
            $initial_key = $file_info['initial key'];
        // next get standard key value
        else if (isset($file_info['initial_key']))
            $initial_key = $file_info['initial_key'];
        // else check comment
        else if (isset($file_info['comment']))
        {
            // split on " - " in comment
            $comment_parts = explode(' - ', $file_info['comment']);
            // verify we have two
            if (count($comment_parts) != 2)
                return null;
            // initial key is first part
            $initial_key = $comment_parts[0];
        }
        else
            return null;

        // return a valid musical key
        return MixingWheel::get_key($initial_key);

    }

    protected static function get_energy($file_info)
    {

        // get the energy level value
        if (isset($file_info['energylevel']))
            $energy = $file_info['energylevel'];
        // next check comment
        else if (isset($file_info['comment']))
        {
            // split on " - " in comment
            $comment_parts = explode(' - ', $file_info['comment']);
            // verify we have two
            if (count($comment_parts) == 2)
                $energy = $comment_parts[1];
            // else assume it is a lone energy value
            else if (count($comment_parts) == 1)
                $energy = $comment_parts[0];
        }
        else
            return null;

        // see if energy is numeric
        if (is_numeric($energy))
            $energy = (int)$energy;
        else
            return null;

        // verify energy between 1 and 10
        if (($energy > 0) and ($energy <= 10))
            return $energy;
        // fail
        return null;

    }

    protected static function get_rating($file_info)
    {

        // see if it is set
        if (!isset($file_info['rating']))
            return null;

        // get rating
        $rating = $file_info['rating'];
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
        // fail
        return null;

    }

}