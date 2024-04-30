pipeline {
    agent any
    environment {
        SERVER_HOST = '192.168.200.14'
        SERVER_PORT = '22'
        REMOTE_DIRECTORY = '/var/www/html/demo57.iitpl.com/wp-content/plugins/payu-woocommerce'
    }
    stages {
        stage('Checkout') {
            steps {
                git branch: 'master', credentialsId: 'gitlabtoken', url: 'http://git.orangemantra.org/helpdesk/payu-woocommerce.git'
            }
        }
        stage('SonarQube Analysis') {
            steps {
                script {
                    def scannerHome = tool 'sonarqubescanner-5.0.1'
                    withSonarQubeEnv() {
                        sh "${scannerHome}/bin/sonar-scanner"
                    }
                }
            }
        }
        stage('Quality Gate') {
            steps {
                timeout(time: 30, unit: 'MINUTES') {
                    waitForQualityGate abortPipeline: true
                }
            }
        } 
        stage('Deploy') {
            steps {
                script {
                    def server = [
                        name: 'SSH Server',
                        host: env.SERVER_HOST,
                        port: env.SERVER_PORT,
                    ]
 
                    sshPublisher(
                        publishers: [
                            sshPublisherDesc(
                                configName: 'demo57',
                                transfers: [
                                    sshTransfer(
                                        sourceFiles: '**',
                                        removePrefix: '',
                                        remoteDirectory: env.REMOTE_DIRECTORY
                                    )
                                ]
                            )
                        ]
                    )
                }
            }
        }
        stage('Commands') {
            steps {
                script {
                    def sshCommand = "ssh demo57@${env.SERVER_HOST} 'pwd && ls -al && git pull origin master'"
                    sh sshCommand
                }
            }
        }
    }
    post {
        always {
            emailext body: '''
            Hi,
            
            $PROJECT_NAME - Build # $BUILD_NUMBER - $BUILD_STATUS:
            
            Check console output at $BUILD_URL to view the results.
            
            Thanks,
            Jenkins
            ''',
            subject: '$PROJECT_NAME - Build # $BUILD_NUMBER - $BUILD_STATUS:',
            to: 'yadav.raman@orangemantra.in,raj.yash@orangemantra.in', replyTo: '$DEFAULT_REPLYTO'
        }
    }
}
