#!/usr/bin/python
#
# Copyright Calin-Andrei Burloiu, calin.burloiu@gmail.com
#
# Automatically publishes videos in P2P-Tube DB based on the video files and
# a videos info file. Parameters: videos_info_file videos_directory category
#
import sys
import MySQLdb
import os
import fnmatch
import subprocess
import string
import json

# cms_content table
class VideosTable:
    tableName = "videos"
    user_id = 1
    thumbs_count = 1
    default_thumb = 0
    
    directory = os.curdir
    default_video_ext = 'ogv'

    def __init__(self, dbCur, directory, name, title, description, tags, category):
        self.dbCur = dbCur
        self.directory = directory

        self.name = name
        self.title = title
        self.description = description
        self.duration, self.formats = self.findVideosMeta()
        self.formats_json = json.dumps(self.formats, separators=(',', ':'))
        self.category = category
        
        tagList = tags.split(',')
        self.tags = {}
        for tag in tagList:
            self.tags[tag.strip()] = 0
        self.tags_json = json.dumps(self.tags, separators=(',', ':'))
        
    def getVideoDefinition(self, fileName):
        pipe = subprocess.Popen('mediainfo --Inform="Video;%Height%" ' + os.path.join(self.directory, fileName), shell=True, stdout=subprocess.PIPE).stdout
        height = pipe.readline().strip()

        pipe = subprocess.Popen('mediainfo --Inform="Video;%ScanType%" ' + os.path.join(self.directory, fileName), shell=True, stdout=subprocess.PIPE).stdout
        scanType = pipe.readline().strip()
        if scanType == '' or scanType == 'Progressive':
            scanType = 'p'
        elif scanType == 'Interlaced':
            scanType = 'i';

        return height + scanType

    def getVideoDuration(self, fileName):
        pipe = subprocess.Popen('mediainfo --Inform="General;%Duration/String3%" ' + os.path.join(self.directory, fileName), shell=True, stdout=subprocess.PIPE).stdout
        output = pipe.readline().strip()
        dotPos = output.find('.')
        if output[0:2] == '00':
            duration = output[3:dotPos]
        else:
            duration = output[:dotPos]

        return duration
        

    # Returns a pair with duration and formats list.
    def findVideosMeta(self):
        files = [f for f in os.listdir(self.directory) if os.path.isfile(os.path.join(self.directory, f))]
        files = fnmatch.filter(files, self.name + "*")

        # Duration not set
        duration = None

        # Formats list
        formats = []
        for f in files:
            if f.find('.tstream') == -1:
                # Duration (if not set yet)
                if duration == None:
                    duration = self.getVideoDuration(f)
                format_ = {}
                format_['def'] = f[(f.rfind('_')+1):f.rfind('.')]
                ext = f[(f.rfind('.')+1):]
                if ext != self.default_video_ext:
                    format_['ext'] = ext
                if format_['def'] != self.getVideoDefinition(f):
                    raise VideoDefException(f)
                formats.append(format_)

        return (duration, formats)

    def insert(self):
        if self.duration == None or self.formats_json == None or self.tags_json == None:
            print "Bzzzz"
        query = "INSERT INTO `" + self.tableName + "` (name, title, description, duration, formats, category, user_id, tags, date, thumbs_count, default_thumb) VALUES ('" + self.name + "', '" + self.title + "', '" + self.description + "', '" + self.duration + "', '" + self.formats_json + "', '" + self.category + "', " + str(self.user_id) + ", '" + self.tags_json + "', NOW(), " + str(self.thumbs_count) + ", " + str(self.default_thumb) + ")"
        self.dbCur.execute(query)    
    
    @staticmethod
    def getAllNames(dbCur, category):
        allNames = set()
        query = "SELECT name FROM `" + VideosTable.tableName + "` WHERE category = '" + category + "'"
        dbCur.execute(query)

        while(True):
            row = dbCur.fetchone()
            if row == None:
                break
            allNames.add(row[0])

        return allNames


class VideoDefException(Exception):
    def __init__(self, value):
        self.value = 'Invalid video definition in file name "' + value + '"! '

    def __str__(self):
        return repr(self.value)


def main():
    # Check arguments.
    if len(sys.argv) < 3:
        sys.stdout.write('usage: ' + sys.argv[0] + ' videos_info_file videos_dir category\n')
        exit(1)

    # Command line arguments
    fileName = sys.argv[1]
    directory = sys.argv[2]
    category = sys.argv[3]
    if len(sys.argv) == 4:
        thumbsDir = sys.argv[3]
    else:
        thumbsDir = None

    # Connect to DB
    dbConn = MySQLdb.connect(host = 'koala.cs.pub.ro', user = 'koala_p2pnext',
            passwd = 'ahmitairoo', db = 'koala_livinglab')
    dbCur = dbConn.cursor()

    allNames = VideosTable.getAllNames(dbCur, category)

    # Open info file
    file = open(fileName, 'r')

    # Read videos info file
    i = 1
    name = file.readline()
    while name != '':
        name = name.strip()
        title = file.readline().strip()
        description = file.readline().strip()
        tags = file.readline().strip()
        
        if not name in allNames:
            sys.stdout.write(str(i) + '. ' + name + '\r')
            try:
                video = VideosTable(dbCur, directory, name, title, description, tags, category)
                video.insert()
                i = i+1

            except VideoDefException as e:
                sys.stdout.write('\n' + e.value + '\n')

        name = file.readline()

    # Clean-up
    dbCur.close()
    dbConn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
