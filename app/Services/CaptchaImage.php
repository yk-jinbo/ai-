<?php

namespace App\Services;

/**
 * 滑块验证码图片与目标坐标。
 */
final readonly class CaptchaImage
{
    /**
     * @param  string  $backgroundImage  带缺口的背景图 Data URI
     * @param  string  $sliderImage  拼图块 Data URI
     * @param  int  $targetX  拼图块目标左上角 X 坐标
     * @param  int  $targetY  拼图块目标左上角 Y 坐标
     */
    public function __construct(
        public string $backgroundImage,
        public string $sliderImage,
        public int $targetX,
        public int $targetY,
    ) {}
}
