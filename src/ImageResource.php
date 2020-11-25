<?php
namespace ren1244\PDFWriter;

class ImageResource
{
    private $id=0;
    private $images=[]; //filename => key

    function addImage($fileName)
    {
        if(!file_exists($fileName)) {
            throw new \Exception('image file does not exists');
        }
        $info=getimagesize($fileName);
        $info['key']='IM'.(++$this->id);
        $this->images[$fileName]=$info;
        return [$info['key'], $info[0], $info[1]];
    }

    function write($writer)
    {
        $arr=[];
        foreach($this->images as $fname=>$info) {
            if($info[2]===IMG_JPEG) {
                $data=file_get_contents($fname);
                $id=$writer->writeStream($data, false, [
                    'Type' => '/XObject',
                    'Subtype' => '/Image',
                    'BitsPerComponent' => $info['bits'],
                    'Width' => $info[0],
                    'Height' => $info[1],
                    'ColorSpace' => $info['channels']===3?'/DeviceRGB':'/DeviceCMYK',
                    'Filter' => '/DCTDecode'
                ]);
                $arr[]="/{$info['key']} $id 0 R";
            } else {
                throw new \Exception('unsupport image format');
            }
        }
        return '/XObject << '.implode("\n", $arr).' >>';
    }
}
