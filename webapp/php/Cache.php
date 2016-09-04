<?php
class Cache
{
  // キャッシュ生存期間    
  private $_lifeTime;

  // コンストラクタ
  public function __construct($lifeTime = LIFE_TIME)
  {
    $this->_lifeTime = $lifeTime;
  }

  // キャッシュ保存
  public function put($key, $value)
  {
    $filePath = $this->getFilePath($key);
    file_put_contents($filePath, serialize($value));
  }

  // キャッシュ取得
  public function get($key)
  {
    $filePath = $this->getFilePath($key);
    if (file_exists($filePath) && (filemtime($filePath) + $this->_lifeTime) > time())
    {
      return unserialize(file_get_contents($filePath));
    }
    else
    {
      return false;
    }
  }

  // キャッシュファイルパス取得
  private function getFilePath($key)
  {
    return CACHE_DIR . sha1(serialize($key));
  }
}
