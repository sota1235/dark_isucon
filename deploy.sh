# confファイルを撒く
# sudoで実行してください

###
# MySQL
###

# 現ファイルのバックアップを取る
cp /etc/mysql/my.cnf /etc/mysql/my.cnf.bk

# リポジトリのファイルをコピーする
cp ./mysql/my.conf /etc/mysql/
