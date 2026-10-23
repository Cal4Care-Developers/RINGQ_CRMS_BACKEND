#!/bin/bash

cd  /usr/src/mod_openai_audio_stream
git submodule update --init --recursive
mkdir -p build && cd build
cmake ..
make -j$(nproc)
make install
bash /var/www/ringq/app/scripts/startup.sh >/dev/null 2>&1
if [ -f /usr/src/mod_openai_audio_stream/build/mod_openai_audio_stream.so ]; then
	install -o root -g root -m 0644 /usr/src/mod_openai_audio_stream/build/mod_openai_audio_stream.so /usr/lib/freeswitch/mod/mod_openai_audio_stream.so
elif [ -f /usr/local/freeswitch/mod/mod_openai_audio_stream.so ]; then
	install -o root -g root -m 0644 /usr/local/freeswitch/mod/mod_openai_audio_stream.so /usr/lib/freeswitch/mod/mod_openai_audio_stream.so
fi
if [ -f /etc/freeswitch/autoload_configs/modules.conf.xml ] && ! grep -q 'mod_openai_audio_stream' /etc/freeswitch/autoload_configs/modules.conf.xml; then
	sed -i '/<\/modules>/i\    <load module="mod_openai_audio_stream"/>' /etc/freeswitch/autoload_configs/modules.conf.xml
fi
ldd /usr/lib/freeswitch/mod/mod_openai_audio_stream.so
fs_cli -x "load mod_openai_audio_stream"
