<?php

namespace App\Services;

use RuntimeException;

/**
 * 基于本地背景资源生成带缺口的滑块图片。
 */
class CaptchaImageGenerator
{
    public const BACKGROUND_WIDTH = 320;

    public const BACKGROUND_HEIGHT = 160;

    public const PIECE_WIDTH = 40;

    public const PIECE_HEIGHT = 40;

    public const PIECE_INITIAL_X = 0;

    public const PIECE_Y = 60;

    private const MIN_TARGET_X = 80;

    private const TARGET_X_PADDING = 40;

    private const PIECE_PATH = 'M5 5H14C14 -3 26 -3 26 5H35V14C43 14 43 26 35 26V35H26C26 27 14 27 14 35H5V26C13 26 13 14 5 14Z';

    public function __construct(
        private ?string $backgroundDirectory = null,
    ) {}

    /**
     * 生成带随机缺口的背景图和对应拼图块。
     *
     * 业务逻辑：使用 SVG 组合本地 PNG 背景，避免依赖运行环境的 GD 扩展。
     *
     * @return CaptchaImage 背景图、拼图块与服务端目标坐标
     *
     * @throws RuntimeException 背景资源目录不可用或文件读取失败时抛出
     */
    public function generate(): CaptchaImage
    {
        $targetX = random_int(
            self::MIN_TARGET_X,
            self::BACKGROUND_WIDTH - self::PIECE_WIDTH - self::TARGET_X_PADDING,
        );
        $background = $this->loadRandomBackgroundDataUri();

        return new CaptchaImage(
            $this->backgroundDataUri($background, $targetX),
            $this->sliderDataUri($background, $targetX),
            $targetX,
            self::PIECE_Y,
        );
    }

    /**
     * 从固定资源目录读取随机背景，避免把客户端输入当作文件路径。
     */
    private function loadRandomBackgroundDataUri(): string
    {
        $directory = $this->backgroundDirectory ?? resource_path('captcha');
        $files = glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.png');
        if (! is_array($files) || $files === []) {
            throw new RuntimeException('滑块验证码背景资源不存在');
        }

        $contents = file_get_contents($files[array_rand($files)]);
        if ($contents === false) {
            throw new RuntimeException('滑块验证码背景资源无法读取');
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    private function backgroundDataUri(string $background, int $targetX): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="160" viewBox="0 0 320 160">'
            .'<image href="'.$background.'" width="320" height="160"/>'
            .'<path d="'.self::PIECE_PATH.'" transform="translate('.$targetX.' '.self::PIECE_Y.')" fill="#000" fill-opacity=".42" stroke="#fff" stroke-opacity=".72" stroke-width="1"/>'
            .'</svg>';

        return $this->svgDataUri($svg);
    }

    private function sliderDataUri(string $background, int $targetX): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">'
            .'<defs><clipPath id="piece"><path d="'.self::PIECE_PATH.'"/></clipPath></defs>'
            .'<g clip-path="url(#piece)"><image href="'.$background.'" x="-'.$targetX.'" y="-'.self::PIECE_Y.'" width="320" height="160"/></g>'
            .'<path d="'.self::PIECE_PATH.'" fill="none" stroke="#fff" stroke-opacity=".82" stroke-width="1"/>'
            .'</svg>';

        return $this->svgDataUri($svg);
    }

    private function svgDataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
