<?php

namespace App\Services;

/**
 * 返回给前端的滑块验证码数据。
 */
final readonly class LoginCaptchaResult
{
    /**
     * @param  string  $captchaId  本次验证码唯一标识
     * @param  string  $backgroundImage  带缺口背景图 Data URI
     * @param  string  $sliderImage  拼图块图 Data URI
     * @param  int  $backgroundWidth  背景图原始宽度
     * @param  int  $backgroundHeight  背景图原始高度
     * @param  int  $pieceWidth  拼图块宽度
     * @param  int  $pieceHeight  拼图块高度
     * @param  int  $pieceInitialX  拼图块初始 X 坐标
     * @param  int  $pieceY  拼图块固定 Y 坐标
     * @param  int  $expiresIn  验证码有效秒数
     */
    public function __construct(
        public string $captchaId,
        public string $backgroundImage,
        public string $sliderImage,
        public int $backgroundWidth,
        public int $backgroundHeight,
        public int $pieceWidth,
        public int $pieceHeight,
        public int $pieceInitialX,
        public int $pieceY,
        public int $expiresIn,
    ) {}

    /**
     * 返回前端渲染滑块和提交登录所需的数据。
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            // 本次验证码唯一标识；登录时必须原样提交。
            'captcha_id' => $this->captchaId,
            // 带拼图缺口的图片 Data URI，作为滑动轨道背景。
            'background_image' => $this->backgroundImage,
            // 透明背景的拼图块图片 Data URI。
            'slider_image' => $this->sliderImage,
            // 背景原图宽度，前端用于将实际拖动距离换算为 offset_x。
            'background_width' => $this->backgroundWidth,
            // 背景原图高度。
            'background_height' => $this->backgroundHeight,
            // 滑块图片宽度。
            'piece_width' => $this->pieceWidth,
            // 滑块图片高度。
            'piece_height' => $this->pieceHeight,
            // 滑块左上角初始 X 坐标。
            'piece_initial_x' => $this->pieceInitialX,
            // 滑块固定 Y 坐标。
            'piece_y' => $this->pieceY,
            // 验证码剩余有效秒数。
            'expires_in' => $this->expiresIn,
        ];
    }
}
